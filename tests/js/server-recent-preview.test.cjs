const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/server-recent-preview.js', 'utf8');
function setup() {
    function element() {
        const hidden = new Set();
        return {dataset:{}, listeners:{}, isConnected:true, hiddenClasses:hidden,
            classList:{add(c){hidden.add(c)}, remove(c){hidden.delete(c)}},
            addEventListener(e,fn){this.listeners[e]=fn}, removeAttribute(a){delete this[a]},
            pause(){this.paused=true}, load(){this.loads=(this.loads||0)+1}};
    }
    const ids = Object.fromEntries(['Modal','Image','Video','Status','Error','Title','Download','Previous','Next'].map(id => ['recentMedia'+id,element()]));
    const buttons = ['image','video','image'].map((kind,i)=>({dataset:{previewKind:kind, previewSrc:'/preview/'+i,previewName:'Media '+i,downloadUrl:'/download/'+i}}));
    const requests = [], viewed = [];
    const document = {readyState:'complete',getElementById:id=>ids[id],querySelectorAll:selector=>selector==='[data-recent-media]'?buttons:[]};
    vm.runInNewContext(source,{document,window:{greyhoundRecordViewed:b=>viewed.push(b)},Image:function(){requests.push(this)}});
    return {...ids,buttons,requests,viewed,open(i){ids.recentMediaModal.listeners['show.bs.modal']({relatedTarget:buttons[i]})}};
}
test('Recent loads image previews and ignores stale loads after switching or closing',()=>{
    const app=setup(); app.open(0);
    const stale=app.requests[0].onload;
    app.recentMediaNext.listeners.click();
    stale();
    assert.equal(app.recentMediaImage.src,undefined);
    assert.equal(app.recentMediaVideo.src,'/preview/1');
    app.recentMediaNext.listeners.click();
    app.requests[1].onload();
    assert.equal(app.recentMediaImage.src,'/preview/2');
    assert.equal(app.recentMediaDownload.href,'/download/2');
    assert.deepEqual(app.viewed,[app.buttons[2]]);
    app.open(0); const late=app.requests[2].onload;
    app.recentMediaModal.listeners['hidden.bs.modal'](); late();
    assert.equal(app.recentMediaImage.src,undefined);
});
test('Recent stops videos on close and offers recovery after a failed preview',()=>{
    const app=setup(); app.open(1);
    app.recentMediaVideo.onloadeddata();
    assert.deepEqual(app.viewed,[app.buttons[1]]);
    app.recentMediaModal.listeners['hidden.bs.modal']();
    assert.equal(app.recentMediaVideo.paused,true);
    assert.equal(app.recentMediaVideo.src,undefined);
    app.open(0); app.requests[0].onerror();
    assert.equal(app.recentMediaError.hiddenClasses.has('d-none'),false);
    assert.equal(app.recentMediaDownload.href,'/download/0');
    app.recentMediaPrevious.listeners.click(); app.requests[1].onload();
    assert.equal(app.recentMediaImage.src,'/preview/2');
    assert.equal(app.recentMediaError.hiddenClasses.has('d-none'),true);
});
