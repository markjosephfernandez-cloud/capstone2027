const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const view = fs.readFileSync('app/Views/workspace/ifound.php', 'utf8');
const start = view.indexOf('(function initIFoundScan()');
const code = view.slice(start, view.indexOf('\n(function () {\n    var rows', start))
    .replace(/<\?= json_encode\(site_url\('([^']+)'\)\) \?>/g, '"http://localhost/$1"')
    .replace(/<\?= esc\(csrf_token\(\)\) \?>/g, 'csrf');
const flush = () => new Promise(resolve => setImmediate(resolve));
function setup() {
    class Element {
        constructor(){this.children=[];this.style={};this.dataset={};this.attributes={};this.listeners={};this.isConnected=true;this.classes=new Set(['d-none']);this.classList={add:(...names)=>names.forEach(n=>this.classes.add(n)),remove:(...names)=>names.forEach(n=>this.classes.delete(n)),toggle:(name,force)=>{const on=force??!this.classes.has(name);on?this.classes.add(name):this.classes.delete(name);return on;}};}
        setAttribute(name,value){this.attributes[name]=value;}
        append(...children){this.children.push(...children);}
        appendChild(child){this.append(child);}
        replaceChildren(){this.children=[];}
        addEventListener(name,fn){this.listeners[name]=fn;}
        querySelector(){return {name:'csrf',value:'old'};}
    }
    const elements={},get=id=>elements[id]??=new Element(), requests=[],timers=[];
    get('ifoundScanForm').dataset={tokenUrl:'/token',stepUrl:'/step'};get('ifoundScanForm').action='/start';
    get('ifoundScanPicker').scanSelection=[{path:'Event/one.png',name:'one.png'},{path:'Event/two.png',name:'two.png'}];
    let closed=0, refreshed=0;
    const window={fetch:true,FormData:true,location:{href:'http://localhost/workspace/ifound'},GreyhoundAjax:{refresh(){refreshed++;return Promise.resolve();}}};
    class Data {constructor(){this.entries=[];}append(k,v){this.entries.push([k,v]);}}
    vm.runInNewContext(code,{document:{getElementById:get,createElement:()=>new Element(),querySelector:()=>null},window,bootstrap:{Modal:{getInstance:()=>({hide(){closed++;}})}},URL,FormData:Data,Map,Promise,setTimeout(fn){timers.push(fn);},fetch(url,options){return new Promise(resolve=>requests.push({url,options,resolve}));}});
    return {get,window,requests,timers,get closed(){return closed;},get refreshed(){return refreshed;},submit(){return get('ifoundScanForm').listeners.submit({preventDefault(){}});},async reply(i,data,ok=true){requests[i].resolve({ok,status:ok?200:422,json:async()=>data});await flush();}};
}
const state=(extra={})=>({ok:true,jobId:'job',total:2,processed:0,imported:0,skipped:0,failed:0,complete:false,...extra});
test('starting closes picker, sends only chosen paths, and waits for AI before reporting completion',async()=>{
    const app=setup();const running=app.submit();assert.equal(app.closed,1);assert.equal(app.get('ifoundScanProgress').classes.has('d-none'),false);
    await app.reply(0,{ok:true,csrfToken:'fresh'});
    assert.deepEqual(app.requests[1].options.body.entries,[['file_paths[]','Event/one.png'],['file_paths[]','Event/two.png'],['previous_job_id',''],['csrf','fresh']]);
    await app.reply(1,state());
    await app.reply(2,state({processed:1,imported:1,filePath:'Event/one.png',fileId:10,fileStatus:'imported',analysisQueued:true}));
    await app.reply(3,state({processed:2,imported:2,complete:true,filePath:'Event/two.png',fileId:11,fileStatus:'imported',analysisQueued:true}));
    await app.reply(4,{results:[{file_id:10,status:'complete'},{file_id:11,status:'pending'}]});
    assert.equal(app.get('ifoundScanProgressBar').style.width,'50%');assert.equal(app.window.greyhoundIFoundScanBusy,true);assert.equal(app.refreshed,0);
    app.timers.shift()();await flush();
    await app.reply(5,{results:[{file_id:10,status:'complete'},{file_id:11,status:'complete'}]});await running;
    assert.equal(app.get('ifoundScanProgressBar').style.width,'100%');assert.equal(app.window.greyhoundIFoundScanBusy,false);assert.equal(app.refreshed,1);
    assert.equal(app.get('ifoundScanDismiss').hidden,false);
});
test('empty selection does not scan; server errors release controls and remain visible',async()=>{
    const app=setup();app.get('ifoundScanPicker').scanSelection=[];await app.submit();assert.equal(app.requests.length,0);
    app.get('ifoundScanPicker').scanSelection=[{path:'gone.png',name:'gone.png'}];const running=app.submit();
    await app.reply(0,{ok:true,csrfToken:'fresh'});await app.reply(1,{ok:false,message:'Selected file unavailable'},false);await running;
    assert.equal(app.get('ifoundScanErrors').textContent,'Selected file unavailable');assert.equal(app.window.greyhoundIFoundScanBusy,false);assert.equal(app.get('ifoundScanButton').disabled,false);
});
test('minimize keeps scanning and in-app navigation preserves the panel',async()=>{
    const app=setup();const running=app.submit();
    app.get('ifoundScanCollapse').onclick();assert.equal(app.get('ifoundScanCollapse').attributes['aria-expanded'],'false');
    let removed=false,kept;app.window.greyhoundIFoundScanBeforeReplace({querySelector:()=>({remove(){removed=true;}}),appendChild(node){kept=node;}});
    assert.equal(removed,true);assert.equal(kept,app.get('ifoundScanProgress'));assert.equal(app.window.greyhoundIFoundScanBusy,true);
    await app.reply(0,{ok:false,message:'Session expired'},false);await running;
});

test('selections larger than a request batch run in successive batches without dropping files',async()=>{
    const app=setup();app.get('ifoundScanPicker').dataset.batchSize='1';app.get('ifoundScanPicker').scanModes=['objects'];const running=app.submit();
    await app.reply(0,{ok:true,csrfToken:'first'});
    assert.deepEqual(app.requests[1].options.body.entries.filter(x=>x[0]==='file_paths[]'),[['file_paths[]','Event/one.png']]);
    await app.reply(1,state({total:1}));
    await app.reply(2,state({total:1,processed:1,skipped:1,complete:true,filePath:'Event/one.png',fileStatus:'skipped'}));
    assert.equal(app.window.greyhoundIFoundScanBusy,true);
    await app.reply(3,{ok:true,csrfToken:'second'});
    assert.deepEqual(app.requests[4].options.body.entries.filter(x=>x[0]==='file_paths[]'),[['file_paths[]','Event/two.png']]);
    assert.deepEqual(app.requests[4].options.body.entries.filter(x=>x[0]==='previous_job_id'),[['previous_job_id','job']]);
    assert.deepEqual(app.requests[4].options.body.entries.filter(x=>x[0]==='scan_modes[]'),[]);
    await app.reply(4,state({total:1}));
    await app.reply(5,state({total:1,processed:1,skipped:1,complete:true,filePath:'Event/two.png',fileStatus:'skipped'}));await running;
    assert.equal(app.get('ifoundScanCount').textContent,'2 of 2');
    assert.match(app.get('ifoundScanSummary').textContent,/2 already/);
});

test('scan picker no longer exposes scan option controls',()=>{
    const picker = fs.readFileSync('app/Views/components/scan_file_picker.php', 'utf8');
    assert.doesNotMatch(picker, /data-ifound-scan-mode|Scan options|Choose the analysis tasks/);
    assert.doesNotMatch(view, /picker.scanModes|scan_modes:|scanModes.length/);
});
