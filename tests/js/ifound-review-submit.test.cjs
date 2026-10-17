const {test}=require('node:test');const assert=require('node:assert/strict');const vm=require('node:vm');const fs=require('node:fs');
const code=fs.readFileSync('app/Views/workspace/ifound_review_submit_script.php','utf8').replace(/<\/?script>/g,'').replace(/<\?= json_encode\(site_url\('([^']+)'\)\) \?>/g,'"http://localhost/$1"').replace(/<\?= json_encode\(csrf_token\(\)\) \?>/g,'"csrf"');
function setup(responses){
 const calls=[],locations=[],status={},button={},input={value:'old'};
 const form={action:'/review',elements:{namedItem(){return input;}},addEventListener(type,fn){this[type]=fn;}};
 class Data extends Map {constructor(){super([['csrf','old'],['file_ids[]','7'],['review_tags[7]','tag'],['return_q','expo']]);}}
 const window={location:{href:'http://localhost/ifound',assign(url){locations.push(url);}}};
 vm.runInNewContext(code,{document:{getElementById(id){return {ifoundReviewForm:form,ifoundReviewStatus:status,ifoundSaveReview:button}[id];}},window,FormData:Data,URL,AbortController,setTimeout,clearTimeout,fetch:async(url,options)=>{calls.push({url,body:options.body?Object.fromEntries(options.body):null});const r=responses.shift();return {ok:r.status===200,status:r.status,headers:{get(){return r.html?'text/html':'application/json';}},json:async()=>r.data};}});
 return {form,status,button,calls,locations,submit(prevented=false){return form.submit({defaultPrevented:prevented,preventDefault(){this.defaultPrevented=true;}});}};
}
const token=value=>({status:200,data:{ok:true,csrfTokenName:'csrf',csrfToken:value}});
test('fresh token replaces stale token and a CSRF rejection retries exactly once',async()=>{
 const app=setup([token('fresh'),{status:403,html:true},token('newer'),{status:200,data:{ok:true,message:'Saved'}}]);
 await app.submit();assert.equal(app.calls[1].body.csrf,'fresh');assert.equal(app.calls[3].body.csrf,'newer');assert.equal(app.calls[3].body['review_tags[7]'],'tag');assert.equal(app.locations.length,1);
});
test('validation errors remain in the modal and do not navigate',async()=>{
 const app=setup([token('fresh'),{status:422,data:{ok:false,message:'Image has not been processed'}}]);await app.submit();assert.match(app.status.textContent,/not been processed/);assert.equal(app.locations.length,0);assert.equal(app.button.disabled,false);
});
test('tag validation prevents submission; repeated CSRF errors stop after one retry',async()=>{
 const app=setup([token('fresh'),{status:403,html:true},token('newer'),{status:403,html:true}]);await app.submit(true);assert.equal(app.calls.length,0);await app.submit();assert.equal(app.calls.length,4);assert.equal(app.locations.length,0);assert.match(app.status.textContent,/preserved/);
});
