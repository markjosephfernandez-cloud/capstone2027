<?php

use App\Libraries\MediaTagPolicy;
use App\Services\EventDetectionService;
use CodeIgniter\Test\CIUnitTestCase;

final class MediaTagPolicyTest extends CIUnitTestCase
{
    public function testCleanupPreservesContentTagsAndRemovesReviewTags(): void
    {
        $tags = 'Media: Image, Format: JPG, Year: 2026, Dal-uy Events, AI Classified, University Event, AI Review Needed, ads';
        $this->assertSame('2026, Dal-uy Events, University Event, ads', MediaTagPolicy::clean($tags, ['filename' => 'photo.jpg']));
        $this->assertSame('2026, Dal-uy Events, University Event, ads', MediaTagPolicy::clean($tags, ['filename' => 'clip.MP4']));
        $this->assertFalse(MediaTagPolicy::remove('Media Studies', []));
    }

    public function testVideoMetadataAndOrphanTagsAreRecognized(): void
    {
        foreach ([[], ['filename' => 'photo.jpg'], ['type' => 'video'], ['mime_type' => 'video/mp4'], ['media_kind' => 'video'], ['filetype' => 'mov']] as $file) {
            $this->assertTrue(MediaTagPolicy::remove('AI Review Needed', $file));
        }
    }

    public function testGeneratedTagsFollowPolicy(): void
    {
        $method = new ReflectionMethod(EventDetectionService::class, 'automaticTags');
        $service = new EventDetectionService();
        $this->assertSame(['2026', 'Graduation Activities', 'University Event'], $method->invoke($service, ['filename' => 'clip.mp4', 'datecreated' => '2026-01-01'], 'Graduation Activities'));
        $this->assertSame(['2026'], $method->invoke($service, ['filename' => 'photo.jpg', 'datecreated' => '2026-01-01'], null));
    }

    public function testExpressionPrefixesAreRemovedAndDeduplicated(): void
    {
        $this->assertSame('2026, Dal-uy Events, University Event, test images, person, neutral, happy',
            MediaTagPolicy::clean('2026, Dal-uy Events, University Event, test images, person, Expression: neutral, Expression: happy, happy', []));
        $this->assertSame('neutral', MediaTagPolicy::normalize(' expression : neutral '));
    }

    public function testLegacyLabelsAreSimplifiedWithoutDuplicates(): void
    {
        $tags = 'Year: 2026, 2026, Folder: dal uy images, DAL UY IMAGES, AI Review Needed, Family: Reunion';
        $clean = MediaTagPolicy::clean($tags, []);
        $this->assertSame('2026, dal uy images, Family: Reunion', $clean);
        $this->assertSame($clean, MediaTagPolicy::clean($clean, []));
        $this->assertSame('dal uy images', MediaTagPolicy::normalize(' folder : dal uy images '));
        $this->assertSame('', MediaTagPolicy::normalize('Year: '));
    }
}
