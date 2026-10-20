<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_renders_the_primary_sections(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertViewIs('landing.index')
            ->assertSee('lang="id"', false)
            ->assertSee('Data pendidikan Karangasem')
            ->assertSee('id="tentang"', false)
            ->assertSee('id="data"', false)
            ->assertSee('id="kecamatan"', false)
            ->assertSee('id="faq"', false)
            ->assertSee(route('public.dashboard'), false)
            ->assertSee('noindex, nofollow, noarchive')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Content-Language', 'id');

        $this->assertSame(1, substr_count($response->getContent(), '<h1'));
    }

    public function test_landing_page_is_stateless_and_does_not_emit_a_session_cookie(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $this->assertEmpty($response->headers->all('Set-Cookie'));
        $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('s-maxage=3600', (string) $response->headers->get('Cache-Control'));
    }

    public function test_landing_page_exposes_canonical_open_graph_and_json_ld_when_indexable(): void
    {
        config(['seo.indexable' => true]);

        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('<link rel="canonical" href="https://padma.test/">', false)
            ->assertSee('<meta property="og:image" content="https://padma.test/images/og/padma-karangasem-1200x630.jpg">', false)
            ->assertSee('index, follow, max-image-preview:large', false)
            ->assertDontSee('noindex, nofollow, noarchive')
            ->assertHeaderMissing('X-Robots-Tag');

        preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $response->getContent(), $matches);

        $this->assertNotEmpty($matches[1] ?? null, 'JSON-LD tidak ditemukan pada landing page.');

        $schema = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $types = array_column($schema['@graph'], '@type');

        $this->assertSame('https://schema.org', $schema['@context']);
        $this->assertContains('GovernmentOrganization', $types);
        $this->assertContains('WebSite', $types);
        $this->assertContains('WebPage', $types);
        $this->assertContains('FAQPage', $types);

        $faq = collect($schema['@graph'])->firstWhere('@type', 'FAQPage');
        $this->assertCount(count(config('landing.faq')), $faq['mainEntity']);
        $this->assertSame(config('landing.faq.0.question'), $faq['mainEntity'][0]['name']);
    }

    public function test_landing_can_be_disabled_as_a_rollback_path(): void
    {
        config(['landing.enabled' => false]);

        $this->get('/')->assertRedirect(route('public.dashboard'));
    }

    public function test_landing_does_not_expose_dashboard_embed_tokens(): void
    {
        config([
            'metabase.embedding_secret' => 'test-secret-yang-cukup-panjang-minimal-32-karakter',
            'metabase.vip_dashboard_id' => 4,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('/embed/dashboard/', false)
            ->assertDontSee('test-secret-yang-cukup-panjang-minimal-32-karakter');
    }
}
