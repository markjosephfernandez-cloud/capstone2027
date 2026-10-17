const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/server-library-connection.js', 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
function setup(searchPending = false, catalog = false) {
    const nodes = {};
    function node(id) { return nodes[id] ||= {isConnected:true,dataset:{},listeners:{},addEventListener(name,fn){this.listeners[name]=fn;},setAttribute(name,value){this[name]=value;}}; }
    node('serverStorageStatus').dataset = {connected:'1',writable:'1',statusUrl:'/status?folder=Events'};
    node('main').dataset.searchPending = searchPending ? '1' : '0';
    node('main').dataset.catalog = catalog ? '1' : '0';
    const listeners = {}, requests = [], timers = new Map(); let sequence=0, refreshes=0;
    const window = {location:{href:'http://localhost/workspace/server-library'},setTimeout(fn,ms){timers.set(++sequence,{fn,ms});return sequence;},clearTimeout(id){timers.delete(id);},addEventListener(){},removeEventListener(){},GreyhoundAjax:{refresh(){refreshes++;return Promise.resolve();}}};
    const document = {getElementById:node,addEventListener(name,fn){if(name==='DOMContentLoaded')fn();else listeners[name]=fn;},removeEventListener(name){delete listeners[name];}};
    vm.runInNewContext(source,{window,document,URL,AbortController,fetch:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject}))});
    return {node,window,listeners,requests,timers,get refreshes(){return refreshes;},async reply(data){requests.at(-1).resolve({ok:true,json:async()=>data});await flush();},async check(data){node('serverRefreshConnection').listeners.click();await this.reply(data);}};
}
const online = {connected:true,readable:true,writable:true,can_upload:true,can_manage:true};
test('indexing defers automatic checks but manual connection refresh still works', async () => {
    const app = setup(true);
    assert.equal(app.requests.length, 0);
    assert.equal(app.timers.size, 1);
    await app.check(online);
    assert.equal(app.requests.length, 1);
    assert.equal(app.node('serverConnection').textContent, 'Connected');
    app.node('main').dataset.searchPending = '0';
    [...app.timers.values()].find(timer => timer.ms === 15000).fn();
    assert.equal(app.requests.length, 2);
    app.window.greyhoundServerLibraryCleanup();
});
test('connection reports actual read/write access and disables writes on read-only shares',async()=>{
    const app=setup();await app.reply(online);
    assert.equal(app.node('serverAccess').textContent,'Access: Read & write');
    assert.equal(app.node('mediaNewButton').disabled,false);
    assert.equal(app.requests[0].url,'/status?folder=Events');
    await app.check({...online,writable:false,can_upload:false,can_manage:false});
    assert.equal(app.node('serverAccess').textContent,'Access: Read only');
    assert.equal(app.node('mediaNewButton').disabled,true);
    const event={target:{action:'http://localhost/workspace/server-library/bulk-trash'},preventDefault(){this.prevented=true;},stopImmediatePropagation(){}};
    app.listeners.submit(event);assert.equal(event.prevented,true);
    await app.check(online);assert.equal(app.node('mediaNewButton').disabled,false);
});
test('disconnect and reconnect refresh the listing while preserving role restrictions',async()=>{
    const app=setup();await app.reply({connected:false,readable:false,writable:false,can_upload:false,can_manage:false});
    assert.equal(app.node('serverConnection').textContent,'Disconnected');
    assert.equal(app.node('serverAccess').textContent,'Access: Unavailable');
    await app.check({...online,can_upload:false,can_manage:false});
    assert.equal(app.refreshes,1);
    assert.equal(app.node('mediaNewButton').disabled,true);
    assert.match(app.node('serverConnectionHelp').textContent,/application account has read-only/);
});
test('failed checks show unverified access and cleanup aborts outstanding polling',async()=>{
    const app=setup();app.requests[0].reject(new Error('Timeout'));await flush();
    assert.equal(app.node('serverConnection').textContent,'Unable to check');
    assert.equal(app.node('serverAccess').textContent,'Access: Unverified');
    assert.equal(app.node('mediaNewButton').disabled,true);
    app.node('serverRefreshConnection').listeners.click();
    app.window.greyhoundServerLibraryCleanup();
    assert.equal(app.requests[1].options.signal.aborted,true);
    await app.reply(online);
    assert.equal(app.timers.size,0);
    assert.equal(app.node('serverAccess').textContent,'Access: Unverified');
});

test('catalog mode checks on opening even during indexing without recurring polling or page refresh', async () => {
    const app = setup(true, true);
    assert.equal(app.requests.length, 1);
    assert.equal(app.requests[0].url, '/status?folder=Events');
    assert.equal(app.node('serverConnection').textContent, 'Checking…');
    await app.reply({...online, connected:false, writable:false});
    assert.equal(app.timers.size, 0);
    await app.check(online);
    assert.equal(app.node('serverConnection').textContent, 'Connected');
    assert.equal(app.refreshes, 0);
    assert.equal(app.timers.size, 0);
});

test('unchecked catalog access allows upload selection and submission until a check confirms read-only access', async () => {
    const app = setup(false, true);
    const picker = () => ({target:{closest:()=>true},preventDefault(){this.prevented=true;},stopImmediatePropagation(){}});
    const upload = () => ({target:{action:'http://localhost/workspace/server-library/upload'},preventDefault(){this.prevented=true;},stopImmediatePropagation(){}});
    let click = picker(), submit = upload();
    app.listeners.click(click);
    app.listeners.submit(submit);
    assert.equal(click.prevented, undefined);
    assert.equal(submit.prevented, undefined);
    assert.equal(app.requests.length, 1);
    await app.reply({...online, writable:false, can_upload:false, can_manage:false});
    click = picker(); submit = upload();
    app.listeners.click(click);
    app.listeners.submit(submit);
    assert.equal(click.prevented, true);
    assert.equal(submit.prevented, true);
});

test('a failed automatic catalog check does not block uploads and can be retried', async () => {
    const app = setup(false, true);
    app.node('mediaNewButton').disabled = false;
    app.requests[0].reject(new Error('Timeout'));
    await flush();
    assert.equal(app.node('serverConnection').textContent, 'Unable to check');
    assert.equal(app.node('mediaNewButton').disabled, false);
    const event = {target:{action:'http://localhost/workspace/server-library/upload'},preventDefault(){this.prevented=true;},stopImmediatePropagation(){}};
    app.listeners.submit(event);
    assert.equal(event.prevented, undefined);
    await app.check(online);
    assert.equal(app.node('serverConnection').textContent, 'Connected');
});

test('leaving a catalog folder aborts its check and ignores late responses', async () => {
    const app = setup(false, true);
    app.window.greyhoundServerLibraryCleanup();
    assert.equal(app.requests[0].options.signal.aborted, true);
    await app.reply(online);
    assert.equal(app.node('serverConnection').textContent, 'Checking…');
    assert.equal(app.timers.size, 0);
});
