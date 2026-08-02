<?php

namespace App\Services;

use App\Models\Configuration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

class MercadoLivreScrap
{
    public string $mlQuery;
    public string $mlAffiliateTag;
    public string $mlCookie;
    public string $mlCsrfToken;
    public string $mlAffiliateEndpoint;
    public string $baseUrl;
    public string $marketplace;

    public function __construct()
    {
        $configuration = Configuration::query()->firstOrFail();

        $query = $configuration->query;

        $this->marketplace = 'mercadolivre';
        $this->mlQuery = is_string($query)
            ? $query
            : json_encode($query, JSON_THROW_ON_ERROR);

        $this->mlAffiliateTag = (string) $configuration->affiliate_tag;
        $this->mlCookie = (string) $configuration->cookie;
        $this->mlCsrfToken = (string) $configuration->csrf_token;

        $this->mlAffiliateEndpoint = (string) (
            $configuration->affiliate_endpoint
            ?: 'https://www.mercadolivre.com.br/affiliate-program/api/v2/affiliates/createLink'
        );

        $this->baseUrl = (string) $configuration->base_url;
    }

    public function scrap(): array
    {
        try {
            $products = [];

            foreach ($this->createLinks() as $linkData) {
                try {
                    $response = Http::withHeaders($this->scrapingHeaders())
                        ->connectTimeout(15)
                        ->timeout(40)
                        ->retry(3, 1000)
                        ->get($linkData['link'])
                        ->throw();

                    $products = array_merge(
                        $products,
                        $this->extractProducts(
                            $response->body(),
                            $linkData['link']
                        )
                    );
                } catch (Throwable $exception) {
                    Log::warning('Erro ao extrair página do Mercado Livre.', [
                        'url' => $linkData['link'],
                        'page' => $linkData['page'],
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            $products = $this->deduplicateAndLimit($products);

            $result = [];

            foreach ($products as $product) {
                $affiliateLink = $this->createAffiliateLink(
                    $product['link_compra']
                );

                if ($affiliateLink === null) {
                    continue;
                }

                $priceData = $this->extractPricesAndDiscount(
                    $product['preco']
                );

                $result[] = [
                    'nome_produtos' => $product['nome_produto'],
                    'imagem_produtos' => $product['imagem_produto'],
                    'preco_produtos' => $product['preco'],
                    'link_produtos_afiliado' => $affiliateLink,
                    'price_old' => $priceData['price_old'],
                    'price' => $priceData['price'],
                    'discount_pct' => $priceData['discount_pct'],
                ];
            }

            return $result;
        } catch (Throwable $exception) {
            Log::error('Erro ao executar scraping do Mercado Livre.', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            return [];
        }
    }

    public function createLinks(): array
    {
        $links = [];

        $decodedQuery = json_decode(
            $this->mlQuery,
            false,
            512,
            JSON_THROW_ON_ERROR
        );

        $queries = is_array($decodedQuery)
            ? $decodedQuery
            : [$decodedQuery];

        foreach ($queries as $query) {
            if (!is_object($query)) {
                continue;
            }

            $totalPages = max(
                1,
                (int) ($query->pages ?? 1)
            );

            for ($page = 1; $page <= $totalPages; $page++) {
                $parameters = [
                    'category' => $query->category ?? null,
                    'promotion_type' => 'lightning',
                    'page' => $page,
                ];

                if (!empty($query->container)) {
                    $parameters['container_id'] = $query->container;
                }

                $parameters = array_filter(
                    $parameters,
                    fn(mixed $value): bool => $value !== null && $value !== ''
                );

                $separator = str_contains($this->baseUrl, '?')
                    ? '&'
                    : '?';

                $links[] = [
                    'marketplace' => $this->marketplace,
                    'name' => $query->name ?? null,
                    'category' => $query->category ?? null,
                    'container' => $query->container ?? null,
                    'flash' => $query->flash ?? null,
                    'page' => $page,
                    'link' => $this->baseUrl
                        . $separator
                        . http_build_query($parameters),
                ];
            }
        }

        return $links;
    }

    private function extractProducts(
        string $html,
        string $pageUrl
    ): array {
        $crawler = new Crawler($html, $pageUrl);

        if ($crawler->filter('.poly-card')->count() === 0) {
            return [];
        }

        $products = $crawler
            ->filter('.poly-card')
            ->each(function (Crawler $card) use ($pageUrl): ?array {
                $name = $this->getFirstText($card, [
                    '.poly-card__content > h3 > a',
                    '.poly-card__content h3 a',
                    'a.poly-component__title',
                ]);

                $image = $this->extractImage($card, $pageUrl);

                $price = $this->getFirstText($card, [
                    'div.poly-card__content > div.poly-component__price',
                    '.poly-card__content .poly-component__price',
                    '.poly-component__price',
                ]);

                $link = $this->getFirstAttribute(
                    $card,
                    [
                        '.poly-card__content > h3 > a',
                        '.poly-card__content h3 a',
                        'a.poly-component__title',
                    ],
                    'href'
                );

                $link = $this->normalizeUrl($link, $pageUrl);

                if (
                    $name === null
                    || $image === null
                    || $price === null
                    || $link === null
                ) {
                    return null;
                }

                $category = $this->categorize($name);
                $nameKey = $this->getNameKey($name);

                return [
                    'nome_produto' => $name,
                    'imagem_produto' => $image,
                    'preco' => $price,
                    'link_compra' => $link,
                    'categoria' => $category['key'],
                    'categoria_label' => $category['label'],
                    'name_key' => $nameKey,
                    'score' => $this->calculateScore(
                        $name,
                        $image,
                        $price,
                        $link,
                        $category['key']
                    ),
                ];
            });

        return array_values(
            array_filter(
                $products,
                fn(?array $product): bool => $product !== null
            )
        );
    }

    private function deduplicateAndLimit(array $products): array
    {
        usort(
            $products,
            fn(array $first, array $second): int
                => $second['score'] <=> $first['score']
        );

        $result = [];
        $exactKeys = [];
        $categoryCounts = [];
        $nameCounts = [];

        $maxItemsPerCategory = 10;
        $maxItemsPerName = 3;

        foreach ($products as $product) {
            $category = $product['categoria'];
            $nameKey = $product['name_key'];

            $exactKey = $this->normalizeText(
                $product['nome_produto']
            ) . '|' . $product['link_compra'];

            $nameCountKey = $category . '|' . $nameKey;

            if (isset($exactKeys[$exactKey])) {
                continue;
            }

            if (
                ($categoryCounts[$category] ?? 0)
                >= $maxItemsPerCategory
            ) {
                continue;
            }

            if (
                ($nameCounts[$nameCountKey] ?? 0)
                >= $maxItemsPerName
            ) {
                continue;
            }

            $exactKeys[$exactKey] = true;

            $categoryCounts[$category]
                = ($categoryCounts[$category] ?? 0) + 1;

            $nameCounts[$nameCountKey]
                = ($nameCounts[$nameCountKey] ?? 0) + 1;

            $result[] = $product;
        }

        return $result;
    }

    private function createAffiliateLink(string $productUrl): ?string
    {
        try {
            $response = Http::withHeaders(
                $this->affiliateHeaders()
            )
                ->connectTimeout(15)
                ->timeout(30)
                ->retry(3, 1000, null, false)
                ->post($this->mlAffiliateEndpoint, [
                    'urls' => [$productUrl],
                    'tag' => $this->mlAffiliateTag,
                ]);

            if ($response->failed()) {
                Log::warning('Erro ao gerar link de afiliado.', [
                    'url' => $productUrl,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return null;
            }

            $shortUrl = data_get(
                $response->json(),
                'urls.0.short_url'
            );

            if (!is_string($shortUrl) || trim($shortUrl) === '') {
                return null;
            }

            return trim($shortUrl);
        } catch (Throwable $exception) {
            Log::warning('Falha na criação do link afiliado.', [
                'url' => $productUrl,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function extractPricesAndDiscount(string $raw): array
    {
        preg_match_all(
            '/R\$\s*((?:\d{1,3}(?:\.\d{3})+|\d+)(?:,\d{2})?)/u',
            $raw,
            $matches
        );

        $prices = [];

        foreach ($matches[1] ?? [] as $value) {
            $number = $this->brMoneyToFloat($value);

            if ($number !== null) {
                $prices[] = $number;
            }
        }

        preg_match(
            '/(\d{1,3})\s*%\s*OFF/iu',
            $raw,
            $discountMatch
        );

        $discount = isset($discountMatch[1])
            ? (int) $discountMatch[1]
            : null;

        $priceOld = null;
        $price = $prices[0] ?? null;

        if ($discount !== null && count($prices) >= 2) {
            $priceOld = $prices[0];
            $price = $prices[1];
        }

        if (
            $discount === null
            && $priceOld !== null
            && $price !== null
            && $priceOld > 0
            && $priceOld > $price
        ) {
            $discount = (int) round(
                (($priceOld - $price) / $priceOld) * 100
            );
        }

        return [
            'price_old' => $priceOld,
            'price' => $price,
            'discount_pct' => $discount,
        ];
    }

    private function brMoneyToFloat(string $value): ?float
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (
            preg_match(
                '/^\d{1,3}(?:\.\d{3})+$/',
                $value
            ) === 1
        ) {
            $value = str_replace('.', '', $value);
        }

        if (!is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function categorize(string $name): array
    {
        $title = $this->normalizeText($name);

        $rules = [
            [
                'key' => 'tv',
                'label' => 'TV / Smart TV',
                'words' => [
                    'smart tv',
                    'televisao',
                    'television',
                    'tv led',
                    'tv qled',
                    'tv oled',
                ],
            ],
            [
                'key' => 'projetor',
                'label' => 'Projetor',
                'words' => [
                    'projetor',
                    'projector',
                    'ansi lumens',
                    'home cinema',
                ],
            ],
            [
                'key' => 'fone',
                'label' => 'Fone / Headphone',
                'words' => [
                    'fone',
                    'headphone',
                    'headset',
                    'earbud',
                    'airpods',
                ],
            ],
            [
                'key' => 'caixa_som',
                'label' => 'Caixa de Som',
                'words' => [
                    'caixa de som',
                    'soundbar',
                    'boombox',
                    'alto falante',
                    'subwoofer',
                    'jbl',
                ],
            ],
            [
                'key' => 'tv_box',
                'label' => 'TV Box / Streaming',
                'words' => [
                    'tv box',
                    'roku',
                    'chromecast',
                    'streaming stick',
                ],
            ],
            [
                'key' => 'suporte_tv',
                'label' => 'Suporte / Acessório TV',
                'words' => [
                    'suporte tv',
                    'suporte de tv',
                    'suporte universal',
                ],
            ],
            [
                'key' => 'informatica',
                'label' => 'Informática',
                'words' => [
                    'notebook',
                    'computador',
                    'processador',
                    'monitor',
                    'memoria ram',
                    'ssd',
                ],
            ],
            [
                'key' => 'celular',
                'label' => 'Celular / Acessórios',
                'words' => [
                    'celular',
                    'smartphone',
                    'capinha',
                    'pelicula',
                    'carregador',
                    'type c',
                ],
            ],
        ];

        foreach ($rules as $rule) {
            foreach ($rule['words'] as $word) {
                if ($this->containsTerm($title, $word)) {
                    return [
                        'key' => $rule['key'],
                        'label' => $rule['label'],
                    ];
                }
            }
        }

        return [
            'key' => 'outros',
            'label' => 'Outros',
        ];
    }

    private function calculateScore(
        string $name,
        string $image,
        string $price,
        string $link,
        string $category
    ): int {
        $score = 50;

        if ($image !== '') {
            $score += 10;
        }

        if ($price !== '') {
            $score += 10;
        }

        if ($link !== '') {
            $score += 10;
        }

        if ($category !== 'outros') {
            $score += 10;
        }

        if (
            str_contains(
                $this->normalizeText($link),
                'click1 mercadolivre com br mclics'
            )
        ) {
            $score -= 5;
        }

        $title = $this->normalizeText($name);

        $valueWords = [
            'oferta',
            'promo',
            'desconto',
            'off',
            'anc',
            'qled',
            '4k',
            'dolby',
            'hdr',
            'wifi',
            'bluetooth',
        ];

        foreach ($valueWords as $word) {
            if ($this->containsTerm($title, $word)) {
                $score++;
            }
        }

        return $score;
    }

    private function getNameKey(string $name): string
    {
        $title = $this->normalizeText($name);

        $keys = [
            'caixa de som',
            'smart tv',
            'tv box',
            'suporte tv',
            'fone',
            'headphone',
            'headset',
            'earbud',
            'airpods',
            'projetor',
            'soundbar',
            'boombox',
            'notebook',
            'computador',
            'monitor',
            'celular',
            'smartphone',
            'carregador',
            'controle',
            'microfone',
            'camera',
            'drone',
            'fonte',
            'cabo',
            'bateria',
            'pilha',
            'painel',
            'kit',
        ];

        foreach ($keys as $key) {
            if ($this->containsTerm($title, $key)) {
                return str_replace(' ', '_', $key);
            }
        }

        $ignoredWords = [
            'de',
            'da',
            'do',
            'das',
            'dos',
            'para',
            'com',
            'sem',
            'em',
            'cor',
            'preto',
            'branco',
            'novo',
        ];

        $words = array_values(
            array_filter(
                explode(' ', $title),
                fn(string $word): bool
                    => mb_strlen($word) >= 3
                    && !in_array($word, $ignoredWords, true)
                    && !is_numeric($word)
            )
        );

        $key = implode(
            '_',
            array_slice($words, 0, 2)
        );

        return $key !== '' ? $key : 'sem_nome';
    }

    private function containsTerm(
        string $text,
        string $term
    ): bool {
        $term = $this->normalizeText($term);

        if (str_contains($term, ' ')) {
            return str_contains($text, $term);
        }

        return preg_match(
            '/(^|\s)' . preg_quote($term, '/') . '(\s|$)/u',
            $text
        ) === 1;
    }

    private function normalizeText(string $value): string
    {
        $value = Str::ascii(
            mb_strtolower($value)
        );

        $value = preg_replace(
            '/[^a-z0-9]+/u',
            ' ',
            $value
        );

        return trim(
            preg_replace('/\s+/u', ' ', $value) ?? ''
        );
    }

    private function getFirstText(
        Crawler $crawler,
        array $selectors
    ): ?string {
        foreach ($selectors as $selector) {
            $node = $crawler->filter($selector);

            if ($node->count() === 0) {
                continue;
            }

            $value = $node->first()->text('', true);

            $value = preg_replace(
                '/\s+/u',
                ' ',
                trim($value)
            );

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function getFirstAttribute(
        Crawler $crawler,
        array $selectors,
        string $attribute
    ): ?string {
        foreach ($selectors as $selector) {
            $node = $crawler->filter($selector);

            if ($node->count() === 0) {
                continue;
            }

            $value = $node->first()->attr($attribute);

            if (
                is_string($value)
                && trim($value) !== ''
            ) {
                return trim($value);
            }
        }

        return null;
    }

    private function extractImage(
        Crawler $card,
        string $pageUrl
    ): ?string {
        $selectors = [
            '.poly-card__portada img.poly-component__picture',
            '.poly-card__portada img',
            'img.poly-component__picture',
        ];

        $src = $this->getFirstAttribute(
            $card,
            $selectors,
            'src'
        );

        if (
            $src !== null
            && !str_starts_with($src, 'data:')
        ) {
            return $this->normalizeUrl($src, $pageUrl);
        }

        $dataSrc = $this->getFirstAttribute(
            $card,
            $selectors,
            'data-src'
        );

        if (
            $dataSrc !== null
            && !str_starts_with($dataSrc, 'data:')
        ) {
            return $this->normalizeUrl($dataSrc, $pageUrl);
        }

        $srcset = $this->getFirstAttribute(
            $card,
            $selectors,
            'srcset'
        );

        if ($srcset === null) {
            return null;
        }

        $sources = array_values(
            array_filter(
                array_map(
                    'trim',
                    explode(',', $srcset)
                )
            )
        );

        if ($sources === []) {
            return null;
        }

        $parts = preg_split(
            '/\s+/',
            $sources[0]
        );

        return $this->normalizeUrl(
            $parts[0] ?? null,
            $pageUrl
        );
    }

    private function normalizeUrl(
        ?string $url,
        string $baseUrl
    ): ?string {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim(
            html_entity_decode(
                $url,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
        );

        if (str_starts_with($url, 'data:')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        if (
            str_starts_with($url, 'http://')
            || str_starts_with($url, 'https://')
        ) {
            return $url;
        }

        $scheme = parse_url(
            $baseUrl,
            PHP_URL_SCHEME
        );

        $host = parse_url(
            $baseUrl,
            PHP_URL_HOST
        );

        if (
            !is_string($scheme)
            || !is_string($host)
        ) {
            return $url;
        }

        if (str_starts_with($url, '/')) {
            return "{$scheme}://{$host}{$url}";
        }

        return "{$scheme}://{$host}/{$url}";
    }

    private function scrapingHeaders(): array
    {
        $headers = [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        ];

        if ($this->mlCookie !== '') {
            $headers['Cookie'] = $this->mlCookie;
        }

        return $headers;
    }

    private function affiliateHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
            'Content-Type' => 'application/json',
            'Downlink' => '10',
            'ECT' => '4g',
            'Origin' => 'https://www.mercadolivre.com.br',
            'Priority' => 'u=1, i',
            'Referer' => 'https://www.mercadolivre.com.br/',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        ];

        if ($this->mlCsrfToken !== '') {
            $headers['X-CSRF-Token'] = $this->mlCsrfToken;
        }

        if ($this->mlCookie !== '') {
            $headers['Cookie'] = $this->mlCookie;
        }

        return $headers;
    }
}
