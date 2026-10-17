const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const code = fs.readFileSync('public/assets/js/server-library-live.js', 'utf8');
function setup() {
    const tasks = new Map(); let id = 0, busy = false, requests = 0;
    const regions = new Map();
    const main = {isConnected:true, dataset:{catalog:'1',catalogState:'running',folderPending:'1'}, querySelector:s=>regions.get(s)};
    const folders = {innerHTML:'old'}; regions.set('#catalogFolders', folders);
    const status = {replaceChildren(){},appendChild(){}};
    const incoming = {dataset:{catalogValid:'1',catalogState:'running',folderPending:'0',searchPending:'1'},querySelector:s=>s==='#catalogFolders'?{innerHTML:'new'}:null};
    const document = {readyState:'complete',hidden:false,activeElement:null,getElementById:id=>id==='main'?main:status,
        querySelector:()=>busy?{}:null,createElement:()=>({setAttribute(){}}),createTextNode:t=>t};
    const window = {location:{href:'http://localhost/library?folder=Events'},setTimeout:f=>{tasks.set(++id,f);return id;},clearTimeout:i=>tasks.delete(i),addEventListener(){},removeEventListener(){}};
    let resolve;
    const context = {window,document,URL,AbortController,DOMParser:class {parseFromString(){return {getElementById:()=>incoming};}},
        fetch:()=>{requests++;return new Promise(r=>{resolve=r;});}};
    vm.runInNewContext(code,context);
    async function tick() { const [key,fn] = tasks.entries().next().value; tasks.delete(key); return fn(); }
    return {window,main,folders,tasks,tick,setBusy:b=>busy=b,requests:()=>requests,finish:(status=200)=>resolve({ok:true,status,redirected:false,text:async()=>'<html>'})};
}
test('updates only results without navigation or replacing the workspace',async()=>{
    const s=setup();const pending=s.tick();s.finish();await pending;
    assert.equal(s.folders.innerHTML,'new');assert.equal(s.main.dataset.folderPending,'0');
    assert.equal(s.window.location.href,'http://localhost/library?folder=Events');assert.equal(s.tasks.size,1);
});
test('pauses polling during selection or editing',async()=>{
    const s=setup();s.setBusy(true);await s.tick();assert.equal(s.requests(),0);assert.equal(s.folders.innerHTML,'old');
});
test('does not apply a response if user starts interacting during the request',async()=>{
    const s=setup();const pending=s.tick();s.setBusy(true);s.finish();await pending;assert.equal(s.folders.innerHTML,'old');
});
test('navigation cancels updates and scheduled retries',async()=>{
    const s=setup();const pending=s.tick();s.window.greyhoundCatalogLiveCleanup();s.finish();await pending;
    assert.equal(s.folders.innerHTML,'old');assert.equal(s.tasks.size,0);
});

test('unchanged response leaves existing thumbnails and markup intact',async()=>{
    const s=setup();const pending=s.tick();s.finish(204);await pending;
    assert.equal(s.folders.innerHTML,'old');assert.equal(s.tasks.size,1);
});
