<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'metabase.site_url' => 'https://metabase.test',
            'metabase.public_dashboard_uuid' => '65fb774f-493a-4bbc-b438-34bf5de4c174',
            'metabase.public_dashboard_id' => 2,
            'metabase.vip_dashboard_id' => 4,
            'metabase.embedding_secret' => 'test-secret-yang-cukup-panjang-minimal-32-karakter',
        ]);
    }

    public function test_robots_disallows_indexing_when_seo_is_disabled(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee("User-agent: *\nDisallow: /", false)
            ->assertDontSee('Sitemap:');
    }

    public function test_robots_allows_public_pages_and_points_to_sitemap_when_enabled(): void
    {
        config(['seo.indexable' => true]);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Allow: /', false)
            ->assertSee('Disallow: /admin', false)
            ->assertSee('Disallow: /login', false)
            ->assertSee('Disallow: /vip', false)
            ->assertSee('Sitemap: https://padma.test/sitemap.xml', false);
    }

    public function test_sitemap_is_hidden_when_seo_is_disabled(): void
    {
        $this->get('/sitemap.xml')->assertNotFound();
    }

    public function test_sitemap_lists_the_landing_page_when_enabled(): void
    {
        config(['seo.indexable' => true]);

        $response = $this->get('/sitemap.xml');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $xml = simplexml_load_string((string) $response->getContent());

        $this->assertNotFalse($xml);
        $this->assertSame('https://padma.test/', (string) $xml->url->loc);
        $this->assertSame(config('landing.meta.updated_at'), (string) $xml->url->lastmod);
    }

    public function test_private_pages_send_noindex_headers(): void
    {
        $this->get('/dashboard')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, follow')
            ->assertSee('<meta name="robots" content="noindex, follow">', false);

        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->get('/admin/login')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->get('/vip/dashboard')
            ->assertRedirect(route('login'))
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
