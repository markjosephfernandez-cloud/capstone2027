const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const code=fs.readFileSync('app/Views/workspace/ifound_analysis_script.php','utf8').replace(/<\/?script>/g,'').replace(/<\?= json_encode\(site_url\('([^']+)'\)\) \?>/g,'"http://localhost/$1"').replace(/<\?= json_encode\(csrf_token\(\)\) \?>/g,'"csrf"');
const flush=()=>new Promise(resolve=>setImmediate(resolve));
function setup(){
 const button={dataset:{},isConnected:true,addEventListener(name,fn){this[name]=fn;}},status={textContent:'',children:[],appendChild(n){this.children.push(n);}},requests=[],timers=[];
 const row={dataset:{fileId:'26746'},querySelector(){return {checked:true};}};
 const document={getElementById(id){return id==='ifoundAnalyzeSelected'?button:status;},querySelectorAll(){return [row];},querySelector(){return row;},createElement(){return {};}};
 const window={location:{href:'http://localhost/workspace/ifound'}};
 class Data {constructor(){this.items=[];}append(k,v){this.items.push([k,v]);}}
 vm.runInNewContext(code,{window,document,URL,AbortController,FormData:Data,Date,Array,setTimeout(fn,ms){timers.push({fn,ms});return timers.length;},clearTimeout(){},fetch(url,opts){return new Promise(resolve=>requests.push({url,opts,resolve}));}});
 return {button,status,requests,window,async reply(index,data,ok=true){requests[index].resolve({ok,json:async()=>data});await flush();},async poll(){timers.find(t=>t.ms===5000).fn();await flush();}};
}
test('selected analysis refreshes CSRF, queues IDs, polls and offers results without navigating',async()=>{
 const app=setup();app.button.click();assert.equal(app.button.disabled,true);
 await app.reply(0,{csrfToken:'fresh',csrfTokenName:'csrf'});
 assert.deepEqual(app.requests[1].opts.body.items,[['csrf','fresh'],['file_ids[]','26746']]);
 await app.reply(1,{queued:[26746],skipped:0,unavailable:0});
 assert.match(app.status.textContent,/1 images queued/);
 await app.poll();assert.match(app.requests[2].url,/analysis-status/);
 await app.reply(2,{results:[{file_id:26746,status:'complete'}]});
 assert.match(app.status.textContent,/1 of 1 completed/);assert.equal(app.button.disabled,false);
 assert.equal(app.status.children[0].textContent,'Refresh results');
});
test('failure shows feedback and navigation aborts outstanding progress checks',async()=>{
 const app=setup();app.button.click();await app.reply(0,{csrfToken:'fresh'});await app.reply(1,{queued:[26746],skipped:0,unavailable:0});await app.poll();
 await app.reply(2,{results:[{file_id:26746,status:'failed'}]});assert.match(app.status.textContent,/failed or unavailable/);
 app.button.click();app.window.greyhoundIFoundAnalysisCleanup();assert.equal(app.requests[3].opts.signal.aborted,true);
});
