<?php

use App\Controllers\Web\SearchSuggestionsController;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Session\Session;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

final class SearchSuggestionsTest extends CIUnitTestCase
{
    private array $actor = ['isLoggedIn'=>true,'employee_id'=>7,'role'=>'super_admin'];

    private function suggestions(string $scope, string $term): Response
    {
        $session = $this->createMock(Session::class);
        $session->method('get')->willReturn($this->actor);
        Services::injectMock('session',$session);
        $request = new IncomingRequest(config('App'), new URI('http://example.com/'), null, new UserAgent());
        $request->setGlobal('get',['q'=>$term]);
        $controller = new SearchSuggestionsController();
        $controller->initController($request,new Response(config('App')),service('logger'));
        return $controller->index($scope);
    }

    public function testSuggestionEndpointsEnforceRolesBeforeQuerying(): void
    {
        foreach (['department','writer','admin'] as $role) {
            $this->actor['role']=$role;
            $this->assertSame(403,$this->suggestions('audit-users','Mark')->getStatusCode());
            $this->assertSame(403,$this->suggestions('users','Mark')->getStatusCode());
        }
        $this->actor['isLoggedIn']=false;
        $this->assertSame(401,$this->suggestions('ifound','image')->getStatusCode());
    }

    public function testSuggestionsFindHistoricalAuditUsersAndExcludeOtherActors(): void
    {
        $db = db_connect();
        $this->assertSame(':memory:',$db->database);
        $forge = \Config\Database::forge($db);
        $forge->addField(['user_id'=>['type'=>'INTEGER'],'user_name'=>['type'=>'TEXT'],'action'=>['type'=>'TEXT']]);
        $forge->createTable('audit_logs');
        try {
            $db->table('audit_logs')->insertBatch([
                ['user_id'=>7,'user_name'=>'Mark Besario','action'=>'LOGIN'],
                ['user_id'=>7,'user_name'=>'Mark Besario','action'=>'LOGOUT'],
                ['user_id'=>8,'user_name'=>'Other','action'=>'PRIVATE_LOGIN'],
            ]);
            $data=json_decode($this->suggestions('audit-users','Mark')->getBody(),true);
            $this->assertCount(1,$data['suggestions']);
            $this->assertSame('7',$data['suggestions'][0]['value']);
            $this->assertSame('Mark Besario · 7',$data['suggestions'][0]['label']);
            $this->actor['role']='admin';
            $data=json_decode($this->suggestions('audit','LOG')->getBody(),true);
            $this->assertCount(2,$data['suggestions']);
            $this->assertNotContains('PRIVATE_LOGIN',array_column($data['suggestions'],'value'));
            $this->assertSame([],json_decode($this->suggestions('audit','L')->getBody(),true)['suggestions']);
        } finally { $forge->dropTable('audit_logs'); $db->resetDataCache(); }
    }
}
