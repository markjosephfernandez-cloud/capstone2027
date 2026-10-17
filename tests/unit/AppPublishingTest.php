<?php

use App\Controllers\Api\MobileFeedController;
use App\Controllers\Api\MobilePostController;
use App\Controllers\Api\MobileInteractionController;
use App\Database\Migrations\AddAppPublishing;
use App\Services\AppPublishingService;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;

require_once APPPATH . 'Database/Migrations/2026-09-12-000001_AddAppPublishing.php';
require_once APPPATH . 'Database/Migrations/2026-09-15-000001_AddMobileFeatured.php';
require_once APPPATH . 'Database/Migrations/2026-09-15-000002_AddLegacyAppPosts.php';

require_once APPPATH . 'Database/Migrations/2026-10-08-191200_AddTagsToMobilePosts.php';

final class AppPublishingTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $this->assertSame('SQLite3', $db->DBDriver);
        $this->assertSame(':memory:', $db->database);
        foreach (['mobile_featured','files','mobile_posts','mobile_media_likes','mobile_comments','mobile_channel_subscriptions','folder'] as $name) $db->query('DROP TABLE IF EXISTS ' . $db->prefixTable($name));
        $db->query('CREATE TABLE ' . $db->prefixTable('files') . " (id INTEGER PRIMARY KEY, filename TEXT, filepath TEXT, media_kind TEXT, release_status TEXT, is_deleted INTEGER DEFAULT 0, display_title TEXT, description TEXT, filetype TEXT, mime_type TEXT, size INTEGER DEFAULT 10, downloadable INTEGER DEFAULT 1, published_at TEXT, updated_at TEXT, publication_channel TEXT, tags TEXT, classification TEXT, detected_objects TEXT, folder_id INTEGER, employee_id INTEGER)");
        foreach (range(1,7) as $id) $db->table('files')->insert(['id'=>$id,'filename'=>'photo'.$id.'.jpg','filepath'=>'photo.jpg','media_kind'=>$id===7?'video':'image','release_status'=>'withdrawn', 'display_title'=>'Original']);
        $db->table('files')->insert(['id'=>99,'filename'=>'legacy.jpg','media_kind'=>'image','release_status'=>'published', 'display_title'=>'Legacy']);
        (new AddAppPublishing())->up();
        (new \App\Database\Migrations\AddTagsToMobilePosts())->up();
        (new \App\Database\Migrations\AddMobileFeatured())->up();
        foreach (['mobile_media_likes'=>'file_id INTEGER, reaction TEXT', 'mobile_comments'=>'file_id INTEGER', 'mobile_channel_subscriptions'=>'channel_key TEXT', 'folder'=>'id INTEGER, is_deleted INTEGER, name TEXT'] as $name=>$columns) $db->query('CREATE TABLE '.$db->prefixTable($name).' ('.$columns.')');
        service('authContext')->clear();
    }

    private function controller(string $class, array $get = [], ?array $body = null)
    {
        $request = new IncomingRequest(config('App'), new URI('http://example.com/'), $body ? json_encode($body) : null, new UserAgent());
        $request->setGlobal('get', $get);
        $controller = new $class();
        $controller->initController($request, new Response(config('App')), service('logger'));
        return $controller;
    }

    private function receive(array $ids = [1,2,3,4,5,6]): int
    {
        $db = db_connect(); $db->transStart();
        $posts = (new AppPublishingService())->receive($ids, 'Campus celebration', 'Description');
        $db->transComplete();
        return $posts[0];
    }

    private function changes(int $version = 1): array
    {
        return ['title'=>'Edited celebration','description'=>'Edited description','thumbnail_mode'=>'collage','thumbnail_ids'=>[6,5,4,3,2], 'pinned'=>true,'version'=>$version];
    }

    public function testInvalidPostTagsAreRejectedWithoutSaving(): void
    {
        service('authContext')->setActor(['role' => 'admin', 'employee_id' => 7]);
        $id = $this->receive();
        foreach ([42, [false], str_repeat('a', 51), array_map(fn($i) => 'tag' . $i, range(1, 21))] as $tags) {
            $response = $this->controller(MobilePostController::class, [], array_merge($this->changes(), ['tags' => $tags]))->update($id);
            $this->assertSame(422, $response->getStatusCode());
        }
        $post = db_connect()->table('mobile_posts')->where('id', $id)->get()->getRowArray();
        $this->assertSame(1, (int)$post['version']);
        $this->assertEmpty($post['tags']);
    }

    public function testPostTagsAreIndependentSearchableAndClearable(): void
    {
        $db = db_connect();
        $db->table('files')->where('id', 1)->update(['tags' => 'FileOnlySecret']);
        $id = $this->receive();
        $service = new AppPublishingService();
        $this->assertEmpty($db->table('mobile_posts')->where('id', $id)->get()->getRowArray()['tags']);
        $post = $service->update($id, array_merge($this->changes(), ['tags' => '#CampusTag, campustag; Student life']), 7, true);
        $this->assertSame('CampusTag, Student life', $post['tags']);
        $this->assertSame('FileOnlySecret', $db->table('files')->where('id', 1)->get()->getRowArray()['tags']);
        $results = json_decode($this->controller(MobileFeedController::class, ['q' => 'CampusTag'])->search()->getBody(), true)['data'];
        $this->assertCount(1, $results);
        $this->assertSame($post['tags'], $results[0]['tags']);
        foreach ($results[0]['files'] as $file) $this->assertSame($post['tags'], $file['tags']);
        $this->assertSame([], json_decode($this->controller(MobileFeedController::class, ['q' => 'FileOnlySecret'])->search()->getBody(), true)['data']);
        $post = $service->update($id, ['version' => 2, 'pinned' => false], 7, false);
        $this->assertSame('CampusTag, Student life', $post['tags']);
        $post = $service->update($id, ['version' => 3, 'tags' => ''], 7, false);
        $this->assertSame('', $post['tags']);
    }

    public function testIncomingTitlesAreEmptyAndAdminSavedTitlesArePreserved(): void
    {
        service('authContext')->setActor(['role' => 'admin', 'employee_id' => 7]);
        $id = $this->receive(); // An older untouched draft with a generated title.
        $post = json_decode($this->controller(MobilePostController::class)->show($id)->getBody(), true)['data'];
        $this->assertSame('', $post['title']);
        $posts = json_decode($this->controller(MobilePostController::class)->index()->getBody(), true)['data'];
        $this->assertSame('', $posts[0]['title']);

        $input = array_merge($this->changes(), ['title' => 'Multiple Media Items']);
        $saved = $this->controller(MobilePostController::class, [], $input)->update($id);
        $this->assertSame(200, $saved->getStatusCode());
        $reopened = json_decode($this->controller(MobilePostController::class)->show($id)->getBody(), true)['data'];
        $this->assertSame('Multiple Media Items', $reopened['title']);
        $this->assertSame(2, $reopened['version']);

        $db = db_connect();
        $db->transStart();
        $newId = (new AppPublishingService())->receive([7], '', '')[0];
        $db->transComplete();
        $this->assertSame('', $db->table('mobile_posts')->where('id', $newId)->get()->getRowArray()['title']);
        $response = $this->controller(MobilePostController::class, [], ['title' => '', 'version' => 1])->publish($newId);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertNull($db->table('mobile_posts')->where('id', $newId)->get()->getRowArray()['published_at']);
    }

    public function testPostingAndAppEditsPreserveIndividualFileMetadata(): void
    {
        $db = db_connect();
        foreach (range(1, 6) as $id) {
            $db->table('files')->where('id', $id)->update(['display_title' => 'Photo ' . $id, 'description' => 'Description ' . $id]);
        }
        $original = $db->table('files')->select('id, filename, filepath, display_title, description')->where('id <=', 6)->orderBy('id')->get()->getResultArray();
        $postId = $this->receive();
        $this->assertSame($original, $db->table('files')->select('id, filename, filepath, display_title, description')->where('id <=', 6)->orderBy('id')->get()->getResultArray());
        (new AppPublishingService())->update($postId, $this->changes(), 7, true);
        $this->assertSame($original, $db->table('files')->select('id, filename, filepath, display_title, description')->where('id <=', 6)->orderBy('id')->get()->getResultArray());
        $search = json_decode($this->controller(MobileFeedController::class, ['q' => 'Edited celebration'])->search()->getBody(), true);
        $this->assertCount(1, $search['data']);
        $this->assertSame('Edited celebration', $search['data'][0]['title']);
        $this->assertSame('Edited description', $search['data'][0]['description']);
        $this->assertSame('Photo 1', $search['data'][0]['files'][0]['title']);
        $this->assertSame('Description 1', $search['data'][0]['files'][0]['description']);
    }

    public function testFileManagementConfirmationPostsWithoutMetadataFields(): void
    {
        $db = db_connect();
        $db->query('ALTER TABLE ' . $db->prefixTable('files') . ' ADD COLUMN published_by INTEGER');
        $actor = ['isLoggedIn' => true, 'employee_id' => 7, 'role' => 'admin'];
        $session = $this->createMock(\CodeIgniter\Session\Session::class);
        $session->method('get')->willReturnCallback(static fn ($key = null) => $key === null ? $actor : ($actor[$key] ?? null));
        \Config\Services::injectMock('session', $session);
        \Config\Services::injectMock('audit', $this->createMock(\App\Services\AuditService::class));
        $db->table('files')->where('id', 1)->update(['description' => 'Existing description']);
        $db->table('files')->where('id', 4)->update(['display_title' => 'Legacy_title 📷']);

        foreach ([[[1, 1], '', 'Existing description'], [[2, 3], '', ''], [[4], '', '']] as [$ids, $title, $description]) {
            $request = new IncomingRequest(config('App'), new URI('http://example.com/'), null, new UserAgent());
            $request->setGlobal('post', ['post_type' => 'files', 'file_ids' => $ids]);
            $controller = new \App\Controllers\Web\WorkspaceController();
            $controller->initController($request, new Response(config('App')), service('logger'));
            $response = $controller->postMedia();

            $this->assertStringEndsWith('/workspace/published-files', $response->getHeaderLine('Location'));
            $file = $db->table('files')->where('id', $ids[0])->get()->getRowArray();
            $post = $db->table('mobile_posts')->where('id', $file['app_post_id'])->get()->getRowArray();
            $this->assertSame($title, $post['title']);
            $this->assertSame($description, $post['description']);
            $this->assertNull($post['published_at']);
            $this->assertSame(7, (int) $file['published_by']);
            $this->assertCount(count(array_unique($ids)), (new AppPublishingService())->members((int) $post['id']));
        }
    }

    public function testNewPostsArePrivateAcrossFeedsAssetsAndInteractionsUntilPublish(): void
    {
        $id = $this->receive();
        $feed = $this->controller(MobileFeedController::class);
        $this->assertSame([99], array_column(json_decode($feed->home()->getBody(),true)['data'],'id'));
        $this->assertSame([], json_decode($this->controller(MobileFeedController::class,['q'=>'Campus'])->search()->getBody(),true)['data']);
        $this->assertSame([], json_decode($this->controller(MobileFeedController::class)->channels()->getBody(),true)['data']);
        foreach (['preview','thumbnail','stream','download'] as $action) $this->assertSame(404,$this->controller(MobileFeedController::class)->$action(1)->getStatusCode());
        $this->assertSame(404,$this->controller(MobileInteractionController::class)->comments(1)->getStatusCode());
        (new AppPublishingService())->update($id,$this->changes(),7,false);
        $this->assertNull(db_connect()->table('files')->where('id',1)->get()->getRowArray()['app_published_at']);
        (new AppPublishingService())->update($id,$this->changes(2),7,true);
        $items=json_decode($this->controller(MobileFeedController::class)->home()->getBody(),true)['data'];
        $this->assertCount(2,$items);
        $this->assertSame($id,$items[0]['post_id']);
        $this->assertSame('Edited celebration',$items[0]['title']);
        $this->assertSame([6,5,4,3,2],$items[0]['thumbnail_ids']);
        $this->assertCount(6,$items[0]['files']);
        $this->assertTrue($items[0]['pinned']);
    }

    public function testOnlyAdminsCanListEditPublishAndReadPrivateThumbnails(): void
    {
        $id=$this->receive();
        foreach (['department','staff','writer'] as $role) {
            service('authContext')->setActor(['role'=>$role,'employee_id'=>5]);
            $controller=$this->controller(MobilePostController::class);
            foreach (['index'=>null,'show'=>$id,'unpublish'=>$id,'publish'=>$id,'update'=>$id,'thumbnail'=>1] as $method=>$argument) {
                $response=$argument===null?$controller->$method():$controller->$method($argument);
                $this->assertSame(403,$response->getStatusCode());
            }
        }
        service('authContext')->setActor(['role'=>'admin','employee_id'=>5]);
        $posts=json_decode($this->controller(MobilePostController::class)->index()->getBody(),true)['data'];
        $this->assertCount(1,$posts); $this->assertNull($posts[0]['published_at']);
        $this->assertCount(5,$posts[0]['thumbnail_ids']);
        $response=$this->controller(MobilePostController::class,[], $this->changes())->publish($id);
        $this->assertSame(200,$response->getStatusCode());
        $this->assertNotNull(json_decode($response->getBody(),true)['data']['published_at']);
    }

    public function testInvalidForeignThumbnailsAndStaleEditsCannotPublish(): void
    {
        $id=$this->receive(); $service=new AppPublishingService();
        try { $service->update($id,array_merge($this->changes(),['thumbnail_ids'=>[99,1,2,3,4]]),5,true); $this->fail('Foreign thumbnail accepted'); }
        catch (InvalidArgumentException) { $this->assertNull(db_connect()->table('files')->where('id',1)->get()->getRowArray()['app_published_at']); }
        $service->update($id,$this->changes(),5,false);
        $this->expectException(RuntimeException::class);
        $service->update($id,$this->changes(),5,true);
    }

    public function testRepostingHidesMediaAgainAndVideosRemainSeparate(): void
    {
        $service=new AppPublishingService(); $id=$this->receive();
        $service->update($id,$this->changes(),5,true);
        $newId=$this->receive([1,2,7]);
        $this->assertNotSame($id,$newId);
        $this->assertNull(db_connect()->table('files')->where('id',1)->get()->getRowArray()['app_published_at']);
        $post=db_connect()->table('mobile_posts')->where('id',$newId)->get()->getRowArray();
        $this->assertSame([1,2],json_decode($post['thumbnail_ids'],true));
        $video=db_connect()->table('files')->where('id',7)->get()->getRowArray();
        $this->assertNotSame($newId,(int)$video['app_post_id']);
        $this->assertNull($video['app_published_at']);
    }

    public function testPublishedPostsLeaveLatestAndUnpublishingRestoresThemPrivately(): void
    {
        $id = $this->receive();
        $service = new AppPublishingService();
        service('authContext')->setActor(['role'=>'admin','employee_id'=>5]);
        $service->update($id, $this->changes(), 5, true);
        $this->assertSame([], json_decode($this->controller(MobilePostController::class)->index()->getBody(), true)['data']);
        $this->assertSame(200, $this->controller(MobilePostController::class)->show($id)->getStatusCode());
        $response = $this->controller(MobilePostController::class, [], ['version'=>2])->unpublish($id);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([$id], array_column(json_decode($this->controller(MobilePostController::class)->index()->getBody(), true)['data'], 'id'));
        $this->assertSame([99], array_column(json_decode($this->controller(MobileFeedController::class)->home()->getBody(), true)['data'], 'id'));
        foreach (['preview','thumbnail','stream','download'] as $action) $this->assertSame(404, $this->controller(MobileFeedController::class)->$action(1)->getStatusCode());
        $this->assertSame(404, $this->controller(MobileInteractionController::class)->comments(1)->getStatusCode());
        $this->assertSame(409, $this->controller(MobilePostController::class, [], ['version'=>2])->unpublish($id)->getStatusCode());
        $service->update($id, $this->changes(3), 5, true);
        $this->assertCount(2, json_decode($this->controller(MobileFeedController::class)->home()->getBody(), true)['data']);
    }

    public function testPinAndUnpinPreservePostMetadataAndPublication(): void
    {
        $id = $this->receive(); $service = new AppPublishingService();
        $service->update($id, $this->changes(), 5, true);
        $post = $service->update($id, ['version'=>2,'pinned'=>false], 5, false);
        $this->assertSame('Edited celebration', $post['title']);
        $this->assertNotNull($post['published_at']);
        $this->assertSame(0, (int)$post['pinned']);
        $this->assertSame(0, (int)db_connect()->table('files')->where('id',1)->get()->getRowArray()['app_pinned']);
        $service->update($id, ['version'=>3,'pinned'=>true], 5, false);
        $items = json_decode($this->controller(MobileFeedController::class)->home()->getBody(), true)['data'];
        $this->assertSame($id, $items[0]['post_id']);
        $this->assertTrue($items[0]['pinned']);
    }

    public function testFeaturedPersistsPhotoOrderAndNeverExposesUnpublishedPhotos(): void
    {
        $class = \App\Controllers\Api\MobileFeaturedController::class;
        $id = $this->receive(); $service = new AppPublishingService();
        $service->update($id, $this->changes(), 5, true);
        service('authContext')->setActor(['role'=>'admin','employee_id'=>5]);
        $response = $this->controller($class, [], ['photo_ids'=>[6,99,2], 'version'=>1])->save();
        $this->assertSame(200, $response->getStatusCode());
        service('authContext')->clear();
        $photos = json_decode($this->controller($class)->index()->getBody(), true)['data'];
        $this->assertSame([6,99,2], array_column($photos, 'id'));
        $this->assertSame('Edited celebration', $photos[0]['title']);
        $this->assertSame('Edited celebration', $photos[0]['display_title']);
        $this->assertSame('Edited description', $photos[0]['description']);
        $this->assertSame('Legacy', $photos[1]['display_title']);
        $this->assertSame(6, $photos[0]['id']);
        $this->assertArrayNotHasKey('files', $photos[0]);
        $this->assertSame(403, $this->controller($class, [], ['photo_ids'=>[], 'version'=>2])->save()->getStatusCode());
        $service->unpublish($id, ['version'=>2]);
        $this->assertSame([99], array_column(json_decode($this->controller($class)->index()->getBody(), true)['data'], 'id'));
        service('authContext')->setActor(['role'=>'super_admin','employee_id'=>5]);
        foreach ([[1], [7], [999], [99,99], ['99']] as $ids) $this->assertSame(422, $this->controller($class, [], ['photo_ids'=>$ids,'version'=>2])->save()->getStatusCode());
        $this->assertSame(409, $this->controller($class, [], ['photo_ids'=>[], 'version'=>1])->save()->getStatusCode());
        $this->assertSame(200, $this->controller($class, [], ['photo_ids'=>[], 'version'=>2])->save()->getStatusCode());
        $this->assertSame([], json_decode($this->controller($class)->index()->getBody(), true)['data']);
    }

    public function testOlderPublicMediaReceivesPostControlsWithoutChangingVisibility(): void
    {
        (new \App\Database\Migrations\AddLegacyAppPosts())->up();
        $file = db_connect()->table('files')->where('id',99)->get()->getRowArray();
        $this->assertNotNull($file['app_post_id']);
        $this->assertNotNull($file['app_published_at']);
        $items = json_decode($this->controller(MobileFeedController::class)->home()->getBody(), true)['data'];
        $this->assertSame([99], array_column($items, 'id'));
        $this->assertSame((int)$file['app_post_id'], $items[0]['post_id']);
        (new AppPublishingService())->unpublish((int)$file['app_post_id'], ['version'=>1]);
        $this->assertSame([], json_decode($this->controller(MobileFeedController::class)->home()->getBody(), true)['data']);
    }
}
