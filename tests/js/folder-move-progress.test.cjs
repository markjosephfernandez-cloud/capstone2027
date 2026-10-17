const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const code=fs.readFileSync('public/assets/js/folder-move-progress.js','utf8');
function setup(){
 class Node {constructor(){this.children=[];this.style={};this.attrs={};this.events={};this.hidden=false;} append(...nodes){this.children.push(...nodes);} appendChild(node){this.children.push(node);} setAttribute(k,v){this.attrs[k]=v;} addEventListener(k,v){this.events[k]=v;} remove(){this.removed=true;}}
 const body=new Node(), tasks=new Map();let id=0;
 const window={setTimeout(fn,delay){tasks.set(++id,{fn,delay});return id;},clearTimeout(id){tasks.delete(id);}};
 vm.runInNewContext(code,{window,document:{body,createElement:()=>new Node()}});
 return {window,body,tasks,Node};
}
test('slow move shows real indeterminate state, survives navigation, and completes',()=>{
 const {window,body,tasks,Node}=setup();const api=window.GreyhoundFolderMove;
 const finish=api.start(['Events/Photos'],'Archive');const panel=body.children[0];
 assert.equal(panel.hidden,true);assert.equal(api.isBusy(),true);assert.equal(api.start(['Other'],'Archive'),null);
 const timer=[...tasks.values()][0];assert.equal(timer.delay,1000);timer.fn();assert.equal(panel.hidden,false);
 assert.equal(panel.children[2].children[0].textContent,'Destination: Archive');
 assert.equal(panel.children[2].children[1].children[0].textContent,'Events/Photos');
 const newBody=new Node();api.beforeReplace(newBody);assert.equal(newBody.children[0],panel);
 const collapse=panel.children[0].children[2];collapse.events.click();assert.equal(panel.children[2].hidden,true);
 finish(true,'Selected folders moved.');assert.equal(api.isBusy(),false);assert.equal(tasks.size,0);
 assert.equal(panel.children[0].children[1].textContent,'Move completed');
 assert.equal(panel.children[0].children[0].hidden,true);
 panel.children[0].children[3].events.click();assert.equal(panel.removed,true);
});
test('quick errors clear the delayed indicator and show feedback without a percentage',()=>{
 const {window,body,tasks}=setup();const finish=window.GreyhoundFolderMove.start(['Photos'],'');
 finish(false,'Destination unavailable.');const panel=body.children[0];
 assert.equal(tasks.size,0);assert.equal(panel.hidden,false);
 assert.equal(panel.children[1].textContent,'Destination unavailable.');
 assert.equal(panel.children[0].children[1].textContent,'Move needs attention');
 assert.equal(panel.children[2].children[0].textContent,'Destination: Root Library');
 finish(true,'Ignore duplicate finish');assert.equal(panel.children[1].textContent,'Destination unavailable.');
});
