<?php

use App\Libraries\AssembledUpload;
use App\Services\ServerLibraryService;
use App\Services\ServerLibraryUploadService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Storage;

final class ServerLibraryUploadTest extends CIUnitTestCase
{
    private string $root;
    private ServerLibraryService $library;
    private ServerLibraryUploadService $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/server-upload-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/share',0775,true);
        $config = new class($this->root) extends Storage {
            public function __construct(private string $directory) { parent::__construct(); }
            public function scanSourceRoot(): string { return $this->directory.'/share'; }
            public function maxUploadBytes(): int { return 100000; }
        };
        $this->library = new ServerLibraryService($config);
        $this->uploads = new class($this->library,$config) extends ServerLibraryUploadService {
            protected function analyze(UploadedFile $file, string $folder): array { return ['event_name'=>'EXPO Activities','classification'=>'EXPO Activities','confidence_score'=>91.0,'needs_review'=>false]; }
        };
    }

    protected function tearDown(): void
    {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->root);
        parent::tearDown();
    }

    private function file(string $name = 'photo.png'): AssembledUpload
    {
        $path = $this->root.'/'.bin2hex(random_bytes(6)).'.png';
        $image = imagecreatetruecolor(20,10);
        imagepng($image,$path); imagedestroy($image);
        return new AssembledUpload($path,$name);
    }

    private function receive(UploadedFile $file, array $overrides = []): array
    {
        return $this->uploads->receive($file,$overrides+['folder_id'=>'','relative_path'=>'','duplicate_mode'=>'media_library','upload_batch_id'=>'batch0001'],['employee_id'=>7,'name'=>'Test uploader']);
    }

    public function testFolderUploadsCreateHierarchyPassportAndSearchableMetadata(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') mkdir($this->root.'/share/Bad\\folder');
        $file = $this->file();
        $result = $this->receive($file,['relative_path'=>'Events/Expo/photo.png']);
        $this->assertSame('Events/Expo/photo.png',$result['path']);
        $this->assertSame(hash_file('sha256',$file->getTempName()),hash_file('sha256',$this->root.'/share/'.$result['path']));
        $state = $this->uploads->state();
        $this->assertSame('EXPO Activities',$state['media'][$result['path']]['passport']['classification']);
        $this->assertSame(1,$this->library->browse('Events/Expo','EXPO Activities',0,null,'png')['file_total']);
        $this->assertSame(0,$this->library->browse('Events/Expo','',0,null,'jpg')['file_total']);
        $this->uploads->rename('Events','Renamed');
        $this->assertArrayHasKey('Renamed/Expo/photo.png',$this->uploads->state()['media']);
        $this->uploads->trash(['Renamed'],7);
        $state = $this->uploads->state();
        $this->assertSame([],$state['media']);
        $this->uploads->restore(array_key_first($state['trash']));
        $this->assertSame('EXPO Activities',$this->uploads->state()['media']['Renamed/Expo/photo.png']['passport']['classification']);
    }

    public function testRepeatedUploadsSaveNormallyWithoutScanningOrOverwriting(): void
    {
        $file = $this->file();
        $hash = hash_file('sha256', $file->getTempName());
        foreach (['media_library', 'upload_batch', 'obsolete-mode'] as $mode) {
            $result = $this->receive($this->file(), ['duplicate_mode'=>$mode]);
            $this->assertFalse($result['duplicate']);
            $this->assertSame(1, $result['uploaded']);
            $this->assertSame($hash, hash_file('sha256', $this->root.'/share/'.$result['path']));
        }
        $this->assertSame([], $this->uploads->state()['duplicates']);
        $this->assertSame(3, $this->library->browse()['file_total']);
        $this->assertSame($hash, hash_file('sha256', $this->root.'/share/photo.png'));
    }

    public function testChunkReceiptsDoNotCreateExtraFilesAndCancellationDropsPartialData(): void
    {
        $file = $this->file();
        $metadata = ['name'=>'photo.png','folder_id'=>'','relative_path'=>'','duplicate_mode'=>'media_library','upload_batch_id'=>'batch0001'];
        $id = str_repeat('a',32); $calls = 0;
        $callback = function ($assembled) use (&$calls,$metadata) { $calls++; return ['status'=>200,'payload'=>$this->uploads->receive($assembled,$metadata,['employee_id'=>7])]; };
        $chunks = $this->uploads->chunks();
        $one = $chunks->accept(7,$id,$file->getSize(),0,$file->getTempName(),$metadata,$callback);
        $two = $chunks->accept(7,$id,$file->getSize(),0,$file->getTempName(),$metadata,$callback);
        $this->assertSame($one,$two); $this->assertSame(1,$calls);
        $this->assertTrue($one['payload']['complete']);
        $this->assertTrue($chunks->cancel(7,str_repeat('b',32))['cancelled']);
        $this->assertSame(1,$this->library->browse()['file_total']);
    }

    public function testUploadRejectsTraversalLinksMismatchedNamesAndUnsupportedTypes(): void
    {
        mkdir($this->root.'/outside');
        symlink($this->root.'/outside',$this->root.'/share/Linked');
        foreach ([['relative_path'=>'../photo.png'],['relative_path'=>'Events/other.png'],['folder_id'=>'Linked'],['relative_path'=>'.hidden/photo.png']] as $metadata) {
            try { $this->receive($this->file(),$metadata); $this->fail('Unsafe upload accepted'); }
            catch (RuntimeException) { $this->assertSame([],$this->library->browse()['items']); }
        }
        $this->expectException(RuntimeException::class);
        $this->receive($this->file('script.php'));
    }


}
