<?php

namespace App\Services;

class LandingSeoService
{
    public function __construct(private LandingSnapshotService $snapshots) {}

    /**
     * Metadata untuk layout landing.
     *
     * @return array{
     *     title: string,
     *     description: string,
     *     canonical: string,
     *     image: string,
     *     image_alt: string,
     *     robots: string,
     *     type: string,
     *     locale: string,
     *     updated_at: string
     * }
     */
    public function meta(): array
    {
        $meta = config('landing.meta');

        return [
            'title' => $meta['title'],
            'description' => $meta['description'],
            'canonical' => $this->homeUrl(),
            'image' => $this->assetUrl(config('seo.default_og_image')),
            'image_alt' => $meta['og_image_alt'],
            'robots' => config('seo.indexable')
                ? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1'
                : 'noindex, nofollow, noarchive',
            'type' => 'website',
            'locale' => 'id_ID',
            'updated_at' => $this->snapshots->updatedAtIso()
                ?? $meta['updated_at'],
        ];
    }

    /**
     * Graph JSON-LD. Hanya berisi data konfigurasi; tidak ada query database.
     *
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        $home = $this->homeUrl();
        $meta = $this->meta();
        $organization = config('landing.organization');
        $faq = config('landing.faq');

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'GovernmentOrganization',
                    '@id' => $home.'#organization',
                    'name' => $organization['name'],
                    'alternateName' => $organization['short_name'],
                    'url' => $organization['url'],
                    'logo' => $this->assetUrl($organization['logo']),
                    'image' => $meta['image'],
                    'telephone' => $organization['telephone'],
                    'email' => $organization['email'],
                    'address' => [
                        '@type' => 'PostalAddress',
                        'streetAddress' => $organization['address']['street'],
                        'addressLocality' => $organization['address']['locality'],
                        'addressRegion' => $organization['address']['region'],
                        'addressCountry' => $organization['address']['country'],
                    ],
                    'areaServed' => [
                        '@type' => 'AdministrativeArea',
                        'name' => $organization['area_served'],
                    ],
                    'sameAs' => $organization['same_as'],
                    'knowsAbout' => $organization['knows_about'],
                    'contactPoint' => [
                        [
                            '@type' => 'ContactPoint',
                            'contactType' => 'customer service',
                            'telephone' => $organization['telephone'],
                            'email' => $organization['email'],
                            'areaServed' => 'ID',
                            'availableLanguage' => ['id'],
                        ],
                    ],
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => $home.'#website',
                    'url' => $home,
                    'name' => 'PADMA Karangasem',
                    'alternateName' => 'Portal Analisis Dapodik menuju Karangasem Agung',
                    'inLanguage' => 'id-ID',
                    'publisher' => ['@id' => $home.'#organization'],
                ],
                [
                    '@type' => 'WebPage',
                    '@id' => $home.'#webpage',
                    'url' => $home,
                    'name' => $meta['title'],
                    'description' => $meta['description'],
                    'isPartOf' => ['@id' => $home.'#website'],
                    'about' => ['@id' => $home.'#organization'],
                    'inLanguage' => 'id-ID',
                    'datePublished' => $meta['updated_at'],
                    'dateModified' => $meta['updated_at'],
                    'primaryImageOfPage' => [
                        '@type' => 'ImageObject',
                        'url' => $meta['image'],
                        'width' => 1200,
                        'height' => 630,
                    ],
                ],
                [
                    '@type' => 'FAQPage',
                    '@id' => $home.'#faq',
                    'mainEntity' => array_map(
                        fn (array $item): array => [
                            '@type' => 'Question',
                            'name' => $item['question'],
                            'acceptedAnswer' => [
                                '@type' => 'Answer',
                                'text' => $item['answer'],
                            ],
                        ],
                        $faq,
                    ),
                ],
            ],
        ];
    }

    /**
     * Canonical selalu memakai APP_URL, bukan Host header request. Dengan
     * begitu URL tunnel, preview, atau host alternatif tidak pernah menjadi
     * canonical resmi.
     */
    public function homeUrl(): string
    {
        return $this->baseUrl().'/';
    }

    /**
     * URL absolut untuk aset SEO (OG image, logo JSON-LD).
     */
    public function assetUrl(string $path): string
    {
        return $this->baseUrl().'/'.ltrim($path, '/');
    }

    /**
     * Base URL tanpa trailing slash.
     */
    protected function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
