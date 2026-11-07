<?php

namespace Tests\Feature;

use App\Content\Remote\NativeWordPressRestPath;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class NativeWordPressReadContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_but_invalid_json_collection_fails_instead_of_looking_empty(): void
    {
        $site = $this->siteWithCredential();
        Http::fake(['https://wp.test/*' => Http::response(['message' => 'temporary upstream error'], 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid JSON collection');

        app(NativeWordPressRestPath::class)->list($site->id, 'posts', ['page' => 1, 'per_page' => 100]);
    }

    public function test_malformed_successful_record_fails_before_sync_projects_rows(): void
    {
        $site = $this->siteWithCredential();
        Http::fake(['https://wp.test/*' => Http::response([
            ['id' => 1, 'title' => 'Legitimate'],
            ['title' => 'Missing required remote identity'],
        ], 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('malformed record');

        app(NativeWordPressRestPath::class)->list($site->id, 'posts');
    }

    public function test_real_empty_page_and_explicit_wordpress_page_exhaustion_are_not_errors(): void
    {
        $site = $this->siteWithCredential();
        Http::fakeSequence()
            ->push([], 200)
            ->push(['code' => 'rest_post_invalid_page_number', 'message' => 'Invalid page'], 400);

        $driver = app(NativeWordPressRestPath::class);
        $this->assertSame([], $driver->list($site->id, 'posts', ['page' => 1, 'per_page' => 100]));
        $this->assertSame([], $driver->list($site->id, 'posts', ['page' => 2, 'per_page' => 100]));
        Http::assertSentCount(2);
    }

    public function test_unrelated_bad_request_cannot_be_misreported_as_page_exhaustion(): void
    {
        $site = $this->siteWithCredential();
        Http::fake(['https://wp.test/*' => Http::response(['code' => 'rest_forbidden', 'message' => 'Denied'], 400)]);

        $this->expectException(RequestException::class);

        app(NativeWordPressRestPath::class)->list($site->id, 'posts', ['page' => 2, 'per_page' => 100]);
    }

    public function test_single_item_read_rejects_a_wrong_resource_identity(): void
    {
        $site = $this->siteWithCredential();
        Http::fake(['https://wp.test/*' => Http::response(['id' => 98, 'title' => 'Wrong item'], 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('incorrect record');

        app(NativeWordPressRestPath::class)->get($site->id, 'posts', 99);
    }

    private function siteWithCredential(): Site
    {
        $tenant = Tenant::query()->create(['name' => 'WordPress', 'slug' => 'wordpress']);
        app(TenantContext::class)->activate($tenant);

        $site = Site::query()->create(['name' => 'WordPress site', 'url' => 'https://wp.test', 'status' => 'active']);
        SiteCredential::query()->create([
            'site_id' => $site->id,
            'username' => 'editor',
            'secret_value' => 'test-only-application-password',
        ]);

        return $site;
    }
}
