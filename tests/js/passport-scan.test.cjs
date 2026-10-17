const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const code = fs.readFileSync('public/assets/js/passport-scan.js', 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
function setup() {
    class Element {
        constructor(){this.children=[];this.style={};this.dataset={};this.attributes={};this.listeners={};this.classes=new Set(['d-none']);this.classList={add:(...names)=>names.forEach(n=>this.classes.add(n)),remove:(...names)=>names.forEach(n=>this.classes.delete(n)),toggle:(name,force)=>{const on=force??!this.classes.has(name);on?this.classes.add(name):this.classes.delete(name);return on;}};}
        setAttribute(name,value){this.attributes[name]=value;}
        append(...children){this.children.push(...children);}
        appendChild(child){this.append(child);}
        replaceChildren(){this.children=[];}
        addEventListener(name,fn){this.listeners[name]=fn;}
    }
    const elements={},get=id=>elements[id]??=new Element(),requests=[];
    get('passportScanForm').dataset={tokenUrl:'/token'};get('passportScanForm').action='/image';
    get('passportScanPicker').scanSelection=[{path:'Event/one.jpg',name:'one.jpg'},{path:'Event/two.jpg',name:'two.jpg'}];
    let closed=0,refreshed=0;
    const window={GreyhoundAjax:{refresh(){refreshed++;return Promise.resolve();}}};
    class Data{constructor(){this.entries=[];}append(k,v){this.entries.push([k,v]);}}
    const context={document:{getElementById:get,createElement:()=>new Element(),querySelector:()=>null},window,bootstrap:{Modal:{getInstance:()=>({hide(){closed++;}})}},AbortController,FormData:Data,setTimeout(){return 1;},clearTimeout(){},fetch(url,options){return new Promise(resolve=>requests.push({url,options,resolve}));}};
    vm.runInNewContext(code,context);
    return {get,window,requests,context,get closed(){return closed;},get refreshed(){return refreshed;},submit(){return get('passportScanForm').listeners.submit({preventDefault(){}});},async reply(i,data,status=200){requests[i].resolve({ok:status===200,status,json:async()=>data});await flush();}};
}
test('selected metadata scan closes picker, sends selected server paths and reports real per-image completion',async()=>{
    const app=setup(),running=app.submit();assert.equal(app.closed,1);assert.equal(app.get('passportScanProgress').classes.has('d-none'),false);
    await app.reply(0,{ok:true,csrfTokenName:'csrf',csrfToken:'fresh'});
    assert.deepEqual(app.requests[1].options.body.entries,[['csrf','fresh'],['file_path','Event/one.jpg']]);
    await app.reply(1,{ok:true,status:'complete'});assert.equal(app.get('passportScanBar').style.width,'50%');
    await app.reply(2,{ok:true,csrfTokenName:'csrf',csrfToken:'next'});
    assert.deepEqual(app.requests[3].options.body.entries,[['csrf','next'],['file_path','Event/two.jpg']]);
    await app.reply(3,{ok:true,status:'skipped'});await running;
    assert.equal(app.get('passportScanBar').style.width,'100%');assert.match(app.get('passportScanSummary').textContent,/1 passports generated · 1 already exist · 0 failed/);assert.equal(app.refreshed,1);
    assert.equal(app.window.GreyhoundPassportScan.busy,false);
});
test('an unreadable image does not stop remaining selections; authentication expiry stops the batch',async()=>{
    const app=setup(),running=app.submit();
    await app.reply(0,{ok:true,csrfTokenName:'csrf',csrfToken:'fresh'});await app.reply(1,{ok:false,message:'Image offline'},422);
    assert.equal(app.requests.length,3);
    await app.reply(2,{ok:false,message:'Sign in again'},401);await running;
    assert.equal(app.requests.length,3);assert.equal(app.get('passportScanLabel').textContent,'Metadata scan stopped.');assert.equal(app.get('passportScanSummary').textContent,'Sign in again');
    assert.equal(app.get('passportScanButton').disabled,false);
});
test('panel survives page replacement and duplicate scan requests are ignored',async()=>{
    const app=setup(),running=app.submit();await app.submit();assert.equal(app.requests.length,1);
    app.get('passportScanCollapse').onclick();assert.equal(app.get('passportScanCollapse').attributes['aria-expanded'],'false');
    let kept,removed=false;app.window.GreyhoundPassportScan.beforeReplace({querySelector:()=>({remove(){removed=true;}}),appendChild(node){kept=node;}});
    assert.equal(removed,true);assert.equal(kept,app.get('passportScanProgress'));
    await app.reply(0,{ok:false,message:'Sign in again'},401);await running;
    assert.equal(app.get('passportScanDismiss').hidden,false);
});

test('iFound and passports share server browsing, folder breadcrumbs and selected paths',async()=>{
    const pickerCode=fs.readFileSync('public/assets/js/ifound-scan-picker.js','utf8');
    for(const prefix of ['ifound','passport']) {
        class El {
            constructor(){this.children=[];this.listeners={};this.dataset={};this.value='';this.isConnected=true;}
            append(...nodes){this.children.push(...nodes);} appendChild(node){this.append(node);} replaceChildren(...nodes){this.children=nodes;}
            setAttribute(){} addEventListener(name,fn){this.listeners[name]=fn;}
        }
        const nodes={},get=id=>nodes[id]??=new El();
        const modal=get(prefix+'ScanPicker');modal.querySelectorAll=()=>prefix==='ifound'?[{value:'events',addEventListener(){}}]:[];modal.id=prefix+'ScanPicker';modal.dataset={limit:'1',browseUrl:'/browse'};
        let submitted=0;get(prefix+'ScanForm').requestSubmit=()=>submitted++;
        const requests=[];
        vm.runInNewContext(pickerCode,{document:{getElementById:id=>id==='ifoundScanPicker'&&prefix==='passport'?null:get(id),createElement:()=>new El()},window:{location:{href:'http://localhost/'}},Map,URL,AbortController,setTimeout(){return 1;},clearTimeout(){},fetch(url){return new Promise(resolve=>requests.push({url,resolve}));}});
        modal.listeners['show.bs.modal']();
        requests[0].resolve({ok:true,json:async()=>({ok:true,items:[{path:'Events',name:'Events',directory:true}],page:1,pages:1})});await flush();
        get(prefix+'PickerFolders').children[0].ondblclick();
        assert.equal(requests[1].url.searchParams.get('folder'),'Events');
        requests[1].resolve({ok:true,json:async()=>({ok:true,items:[{path:'Events/photo.jpg',name:'photo.jpg',directory:false}],page:1,pages:1})});await flush();
        get(prefix+'PickerFiles').children[0].onclick();
        await get(prefix+'PickerContinue').onclick();
        assert.equal(modal.scanSelection[0].path,'Events/photo.jpg');assert.equal(get(prefix+'PickerCrumbs').children[0].textContent,'Server Library');
        get(prefix+'PickerStart').onclick();assert.equal(submitted,1);
        // Selecting a folder expands its images at Continue, rather than scanning a folder token.
        get(prefix+'PickerBack').onclick();get(prefix+'PickerClear').onclick();get(prefix+'PickerCrumbs').children[0].onclick();
        requests[2].resolve({ok:true,json:async()=>({ok:true,items:[{path:'Events',name:'Events',directory:true}],page:1,pages:1})});await flush();
        get(prefix+'PickerFolders').children[0].onclick({detail:0});
        const prepare=get(prefix+'PickerContinue').onclick();
        requests[3].resolve({ok:true,json:async()=>({ok:true,items:[{path:'Events/photo.jpg',name:'photo.jpg',directory:false}],page:1,pages:1})});await prepare;
        assert.equal(modal.scanSelection[0].path,'Events/photo.jpg');assert.equal(get(prefix+'PickerPreview').hidden,false);
        // Large folders remain selectable even when old markup supplies a limit.
        get(prefix+'PickerBack').onclick();
        const oversized=get(prefix+'PickerContinue').onclick();
        requests[4].resolve({ok:true,json:async()=>({ok:true,items:Array.from({length:125},(_,i)=>({path:'Events/'+i+'.jpg',name:i+'.jpg',directory:false})),page:1,pages:1})});await oversized;
        assert.equal(modal.scanSelection.length,125);assert.equal(get(prefix+'PickerStart').hidden,false);assert.equal(get(prefix+'PickerPreviewFiles').children.length,100);
        assert.match(get(prefix+'PickerPreviewSummary').textContent,/all selected images will be scanned/);
        get(prefix+'PickerBack').onclick();get(prefix+'PickerClear').onclick();assert.equal(get(prefix+'PickerContinue').disabled,true);

    }
});
