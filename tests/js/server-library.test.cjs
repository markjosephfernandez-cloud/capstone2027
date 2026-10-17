const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const local = fs.readFileSync('app/Views/workspace/media.php', 'utf8');
const server = fs.readFileSync('app/Views/workspace/server_library.php', 'utf8');
const scripts = source => Array.from(source.matchAll(/<script>([\s\S]*?)<\/script>/g), match => match[1]);

test('Server Library preserves shared interactions alongside server progress controls', () => {
  const shared = scripts(server).filter(script => !script.includes("getElementById('serverSearchProgress')"))
    .map(script => script.replaceAll('greyhound-server-library-view', 'greyhound-file-management-view')
      .replace(/            var postForm = [\s\S]*?(?=            var postTypeInput)/, ''));
  assert.deepEqual(shared, scripts(local).filter(script => !script.includes("getElementById('duplicatesModal')")));
});

test('catalog pages never refresh automatically during indexing', () => {
  assert.equal(scripts(server).some(script => script.includes('function continueSearch')), false);
  assert.doesNotMatch(server, /Refresh results/);
  assert.match(server, /id="catalogLiveStatus" hidden/);
  assert.match(fs.readFileSync('app/Views/admin/control_center.php', 'utf8'), /view\('admin\/server_health'\)/);
});

function setup() {
  const nodes = {};
  function node(id) {
    if (nodes[id]) return nodes[id];
    const el = {id, listeners:{}, dataset:{}, isConnected:true, children:[], classes:new Set(),
      addEventListener(name, fn) {(this.listeners[name] ||= []).push(fn);},
      fire(name, extra={}) {const event={target:this,preventDefault(){this.prevented=true;},...extra};(this.listeners[name]||[]).forEach(fn=>fn(event));return event;},
      removeAttribute(name) {delete this[name];}, getAttribute(name) {return this[name];},
      setAttribute(name,value) {this[name]=value;}, pause(){this.paused=true;}, load(){},
      replaceChildren(){this.children=[];},appendChild(child){this.children.push(child);},closest(){return {hidden:false};},
    };
    el.classList={toggle(name,on){on ? el.classes.add(name) : el.classes.delete(name);}};
    nodes[id]=el;return el;
  }
  const photo = id => ({dataset:{previewKind:'image',previewSrc:'/photo-'+id,previewName:'Photo '+id,downloadUrl:'/download-'+id,logoUrl:'/logo-'+id}, matches(){return true;}});
  const previews=[photo(1),photo(2),photo(1)];
  const boxes=['aabb','aabb','ccdd','ccdd'].map((id,i)=>Object.assign(node('box'+i),{dataset:{mediaId:id},checked:false}));
  const all=[node('all')], actions=[node('download'),node('trash')], requests=[];
  const document={getElementById:node,createElement:()=>node('input'+Math.random()),addEventListener:(_,fn)=>fn(),
    querySelectorAll(selector){
      if(selector.includes('data-media-preview'))return previews;
      if(selector==='[data-media-select]')return boxes;
      if(selector==='[data-media-select-all]')return all;
      if(selector==='[data-bulk-action]')return actions;
      return [];
    }};
  class Image {constructor(){requests.push(this);}}
  const script=scripts(server).find(script=>script.includes("var modal = document.getElementById('mediaPreviewModal')"));
  vm.runInNewContext(script,{document,Image,URL,window:{location:{href:'http://localhost/'}}});
  return {node,previews,boxes,all,actions,requests,open(index){node('mediaPreviewModal').fire('show.bs.modal',{relatedTarget:previews[index]});},load(){requests.at(-1).onload();}};
}

test('grid/list selections stay synchronized and submit unique server identifiers',()=>{
  const app=setup();
  app.boxes[0].checked=true;app.boxes[0].fire('change');
  assert.equal(app.boxes[1].checked,true);
  assert.equal(app.node('bulkSelectionCount').textContent,'1 selected');
  assert.deepEqual(app.node('bulkSelectedInputs').children.map(input=>input.value),['aabb']);
  app.node('selectAllMedia').fire('click');
  assert.deepEqual(app.node('bulkSelectedInputs').children.map(input=>input.value),['aabb','ccdd']);
  assert.ok(app.actions.every(button=>!button.disabled));
  app.node('selectAllMedia').fire('click');
  assert.ok(app.actions.every(button=>button.disabled));
  assert.equal(app.node('bulkActionForm').fire('submit').prevented,true);
});

test('preview gallery deduplicates list/grid buttons and keeps download details synchronized',()=>{
  const app=setup();app.open(0);app.load();
  assert.equal(app.node('mediaPreviewCount').textContent,'1 / 2');
  assert.equal(app.node('mediaPreviewDownload').href,'/download-1');
  app.node('mediaPreviewNext').fire('click');app.load();
  assert.equal(app.node('mediaPreviewImage').src,'/photo-2');
  assert.equal(app.node('mediaPreviewLogoDownload').href,'/logo-2');
  app.node('mediaPreviewModal').fire('keydown',{key:'ArrowRight',target:{closest(){return null;}}});app.load();
  assert.equal(app.node('mediaPreviewImage').src,'/photo-1');
});

test('rapid preview navigation ignores stale requests and closing releases media',()=>{
  const app=setup();app.open(0);const stale=app.requests[0].onload;
  app.node('mediaPreviewNext').fire('click');app.load();stale();
  assert.equal(app.node('mediaPreviewImage').src,'/photo-2');
  app.node('mediaPreviewModal').fire('hidden.bs.modal');
  assert.equal(app.node('mediaPreviewImage').src,undefined);
  assert.equal(app.node('mediaPreviewVideo').paused,true);
});

test('folder counts use bounded parallel reads and abort on navigation',async()=>{
  const code=fs.readFileSync('app/Views/workspace/server_library_counts_script.php','utf8').replace(/<\/?script>/g,'').replace(/<\?= json_encode\([\s\S]*?\?>/g,'"/counts"');
  const cards=Array.from({length:4},(_,i)=>({isConnected:true,dataset:{serverCountPath:'Folder '+i},labels:[{textContent:''}],querySelectorAll(){return this.labels;}}));
  const root={isConnected:true,querySelectorAll(){return cards;}};
  const requests=[];const timers=new Set();
  const window={location:{href:'http://localhost/'},setTimeout(fn){timers.add(fn);return fn;},clearTimeout(fn){timers.delete(fn);},addEventListener(){},removeEventListener(){}};
  vm.runInNewContext(code,{window,URL,AbortController,document:{getElementById:()=>root,addEventListener:(_,fn)=>fn()},fetch:(url,opts)=>new Promise(resolve=>requests.push({url,opts,resolve}))});
  assert.equal(requests.length,2);
  requests[0].resolve({ok:true,json:async()=>({counts:{files:3,folders:1}})});
  await new Promise(resolve=>setImmediate(resolve));
  assert.equal(cards[0].labels[0].textContent,'3 files · 1 folder');
  assert.equal(requests.length,3);
  window.greyhoundServerLibraryBeforeNavigate();
  assert.equal(requests[1].opts.signal.aborted,true);
  assert.equal(requests[2].opts.signal.aborted,true);
  requests[1].resolve({ok:true,json:async()=>({counts:{files:99,folders:99}})});
  await new Promise(resolve=>setImmediate(resolve));
  assert.equal(cards[1].labels[0].textContent,'Loading contents…');
  assert.equal(requests.length,3);
});

test('global search does not queue a count request for every matching folder', () => {
  const code=fs.readFileSync('app/Views/workspace/server_library_counts_script.php','utf8').replace(/<\/?script>/g,'').replace(/<\?= json_encode\([\s\S]*?\?>/g,'"/counts"');
  const label = {textContent:'Loading contents…'};
  const root = {dataset:{serverSearch:'1'},querySelectorAll:()=>[{querySelectorAll:()=>[label]}]};
  vm.runInNewContext(code, {document:{getElementById:()=>root,addEventListener:(_,fn)=>fn()},fetch:()=>assert.fail('Search must not fetch folder counts')});
  assert.equal(label.textContent, 'Open folder to view contents');
});
