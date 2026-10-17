const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/distribution-server-picker.js', 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
function setup() {
    const nodes = new Map(), requests = [];
    function node(id) {
        if (!nodes.has(id)) nodes.set(id, {dataset:{}, listeners:{}, children:[], value:'', hidden:false, isConnected:true,
            addEventListener(name,fn){this.listeners[name]=fn;}, appendChild(child){this.children.push(child);}, replaceChildren(){this.children=[];},
            querySelector(){return node('csrf');}});
        return nodes.get(id);
    }
    node('serverTransmitModal').dataset = {browseUrl:'/browse',selectionUrl:'/selection'};
    vm.runInNewContext(source, {document:{getElementById:node,createElement:()=>({}),addEventListener:(name,fn)=>fn()},
        window:{location:{href:'http://localhost/'},GreyhoundSearch:{attach(input,source,apply){input.suggestions=source;input.applySearch=apply;}}}, URL, AbortController, setTimeout, clearTimeout,
        FormData:class {}, fetch:(url,options)=>new Promise(resolve=>requests.push({url,options,resolve}))});
    const open = () => node('serverTransmitModal').listeners['show.bs.modal']({relatedTarget:{dataset:{action:'/transmit',department:'CCS',requestTitle:'Photos'}}});
    async function reply(data,index=requests.length-1) { requests[index].resolve({ok:true,json:async()=>data}); await flush(); }
    return {node,requests,open,reply};
}
const listing = {folders:[], files:[{id:'6162',name:'Photo <one>.jpg',path:'ab',preview_url:'/preview'}],breadcrumbs:[{id:'',name:'Server Library'}],pagination:{current_page:1,total_pages:1}};
test('server picker previews exact server selection before enabling transmission',async()=>{
    const app=setup();app.open();await app.reply(listing);
    assert.match(app.node('serverPickerFiles').innerHTML,/Photo &lt;one&gt;/);
    app.node('serverPickerFiles').listeners.click({target:{closest:()=>({dataset:{file:'6162'}})}});
    assert.match(app.node('serverPickerCount').textContent,/1 files/);
    app.node('serverPickerContinue').listeners.click();
    assert.equal(app.requests[1].url,'/selection');
    assert.equal(app.requests[1].options.method,'POST');
    await app.reply({files:listing.files,folders:[],csrfToken:'updated'});
    assert.equal(app.node('serverPickerTransmit').hidden,false);
    assert.equal(app.node('csrf').value,'updated');
    assert.equal(app.node('serverPickerInputs').children[0].name,'server_file_ids[]');
    assert.equal(app.node('serverPickerInputs').children[0].value,'6162');
    assert.equal(app.node('serverTransmitForm').action,'/transmit');
    app.node('serverPickerBack').listeners.click();
    let prevented=false;
    app.node('serverTransmitForm').listeners.submit({preventDefault(){prevented=true;}});
    assert.equal(prevented,true);
});
test('closing the picker aborts reads and ignores stale results',async()=>{
    const app=setup();app.open();
    app.node('serverTransmitModal').listeners['hidden.bs.modal']();
    assert.equal(app.requests[0].options.signal.aborted,true);
    await app.reply(listing);
    assert.doesNotMatch(app.node('serverPickerFiles').innerHTML,/Photo/);
    app.open();await app.reply(listing);
    assert.match(app.node('serverPickerFiles').innerHTML,/Photo/);
});

test('direct send carries the department and resets it when switching to a request',async()=>{
    const app=setup();
    app.node('serverTransmitModal').listeners['show.bs.modal']({relatedTarget:{dataset:{mode:'direct',action:'/direct-send-server',department:'CCS'}}});
    await app.reply(listing);
    assert.equal(app.node('serverPickerDepartment').value,'CCS');
    assert.equal(app.node('serverTransmitForm').action,'/direct-send-server');
    assert.equal(app.node('serverTransmitTitle').textContent,'Direct Send');
    assert.equal(app.node('serverPickerTransmit').textContent,'Direct Send');
    app.open();await app.reply(listing);
    assert.equal(app.node('serverPickerDepartment').value,'');
    assert.equal(app.node('serverPickerTransmit').textContent,'Transmit');
});

test('search suggestions do not replace the picker until explicitly applied',async()=>{
    const app=setup();app.open();await app.reply(listing);
    const input=app.node('serverPickerSearch');input.value='Photo';
    assert.equal(input.listeners.input,undefined);
    const suggestions=input.suggestions('Photo',new AbortController().signal);
    await app.reply(listing);await suggestions;
    assert.match(app.node('serverPickerFiles').innerHTML,/Photo &lt;one&gt;/);
    input.applySearch();assert.equal(new URL(app.requests.at(-1).url).searchParams.get('q'),'Photo');
    await app.reply(listing);
});

test('select page retains earlier selections and clear resets all selections',async()=>{
    const app=setup();app.open();await app.reply({...listing,pagination:{current_page:1,total_pages:2}});
    assert.equal(app.node('serverPickerContinue').disabled,true);
    app.node('serverPickerSelectAll').listeners.click();
    assert.equal(app.node('serverPickerContinue').disabled,false);
    assert.equal(app.node('serverPickerSelectAll').textContent,'Unselect all on page');
    app.node('serverPickerNext').listeners.click();
    await app.reply({...listing,files:[{id:'second',name:'Second.jpg'}],pagination:{current_page:2,total_pages:2}});
    app.node('serverPickerSelectAll').listeners.click();assert.match(app.node('serverPickerCount').textContent,/2 files/);
    app.node('serverPickerSelectAll').listeners.click();assert.match(app.node('serverPickerCount').textContent,/1 files/);
    app.node('serverPickerClear').listeners.click();assert.match(app.node('serverPickerCount').textContent,/0 files/);
    assert.equal(app.node('serverPickerContinue').disabled,true);
    assert.equal(app.node('serverPickerClear').disabled,true);
});
