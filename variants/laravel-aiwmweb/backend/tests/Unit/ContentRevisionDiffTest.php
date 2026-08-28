<?php

namespace Tests\Unit;

use App\Content\ContentPlatformService;
use App\Content\Remote\ContentRemoteDriver;
use App\Models\ContentRevision;
use Mockery;
use Tests\TestCase;

class ContentRevisionDiffTest extends TestCase
{
    public function test_revision_comparison_reports_only_changed_fields(): void
    {
        $driver = Mockery::mock(ContentRemoteDriver::class);
        $service = new ContentPlatformService($driver);
        $a = new ContentRevision(['snapshot' => ['title' => 'Old', 'content' => 'Same', 'status' => 'draft']]);
        $b = new ContentRevision(['snapshot' => ['title' => 'New', 'content' => 'Same', 'status' => 'publish']]);
        $diff = $service->compare($a, $b);
        $this->assertSame(['from' => 'Old', 'to' => 'New'], $diff['title']);
        $this->assertSame(['from' => 'draft', 'to' => 'publish'], $diff['status']);
        $this->assertArrayNotHasKey('content', $diff);
    }
}
