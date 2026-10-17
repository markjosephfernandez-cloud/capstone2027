const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
function setup() {
  const node = () => ({listeners:{},style:{},dataset:{},attributes:{},children:[],value:'',isConnected:true,
    classList:{toggle(){}},
    addEventListener(type,fn){(this.listeners[type] ||= []).push(fn);},
    removeEventListener(type,fn){this.listeners[type]=(this.listeners[type]||[]).filter(f=>f!==fn);},
    fire(type,extra={}){const e={target:this,preventDefault(){this.prevented=true;},stopPropagation(){},...extra};(this.listeners[type]||[]).forEach(fn=>fn(e));return e;},
    setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];},
    append(...items){this.children.push(...items);},appendChild(item){this.children.push(item);},replaceChildren(){this.children=[];},remove(){},
    contains(target){return target===this || this.children.includes(target);},
    scrollIntoView(){},getBoundingClientRect(){return {left:10,bottom:40,width:300};}
  });
  const document=node(),window=node(),input=node(),parent=node(),applied=[],pending=[],timers=new Map(); let timerId=0;
  document.createElement=node;document.querySelectorAll=()=>[];document.activeElement=input; input.parentElement=parent;
  vm.runInNewContext(fs.readFileSync('public/assets/js/search-suggestions.js','utf8'),{window,document,AbortController,URL,Map,Set,
    setTimeout(fn,ms){timers.set(++timerId,{fn,ms});return timerId;},clearTimeout(id){timers.delete(id);}});
  window.GreyhoundSearch.attach(input,(term,signal)=>new Promise(resolve=>pending.push({term,signal,resolve})),value=>applied.push(value));
  return {input,parent,applied,pending,window,document,
    type(value){input.value=value;input.fire('input');},
    async tick(){for(const [id,timer] of [...timers]) if(timer.ms===250){timers.delete(id);timer.fn();} await Promise.resolve();},
    async reply(i,items){pending[i].resolve(items);await Promise.resolve();await Promise.resolve();},
    list(){return parent.children[0];}
  };
}
test('typing changes only suggestions; pointer selection submits once with its value',async()=>{
 const app=setup();app.type('Mark');await app.tick();assert.equal(app.applied.length,0);
 await app.reply(0,[{value:'7',label:'Mark Besario · 7'}]);
 const button=app.list().children[0];assert.equal(button.textContent,'Mark Besario · 7');
 assert.equal(button.fire('pointerdown').prevented,true);button.fire('click');
 assert.deepEqual(app.applied,['7']);assert.equal(app.list().hidden,true);
});
test('Enter applies typed text or keyboard selection; composition never submits',async()=>{
 const app=setup();app.type('image');app.input.fire('keydown',{key:'Enter',isComposing:true});assert.equal(app.applied.length,0);
 await app.tick();await app.reply(0,[{value:'image.jpg'}]);app.input.fire('keydown',{key:'ArrowDown'});app.input.fire('keydown',{key:'Enter'});
 assert.deepEqual(app.applied,['image.jpg']);app.type('other');app.input.fire('keydown',{key:'Enter'});assert.deepEqual(app.applied,['image.jpg','other']);
});
test('stale replies and detached pages cannot show old suggestions',async()=>{
 const app=setup();app.type('old');await app.tick();app.type('new');await app.tick();
 assert.equal(app.pending[0].signal.aborted,true);await app.reply(1,[{value:'new.jpg'}]);await app.reply(0,[{value:'old.jpg'}]);
 assert.equal(app.list().children[0].dataset.value,'new.jpg');
 app.input.fire('keydown',{key:'Escape'});assert.equal(app.list().hidden,true);
 app.type('later');await app.tick();app.input.isConnected=false;app.window.GreyhoundSearch.init();await app.reply(2,[{value:'later.jpg'}]);assert.equal(app.list().hidden,true);
});
test('no matches still allow Enter and suggestion labels are plain text',async()=>{
 const app=setup();app.type('empty');await app.tick();await app.reply(0,[]);app.input.fire('keydown',{key:'Enter'});assert.deepEqual(app.applied,['empty']);
 app.type('unsafe');await app.tick();await app.reply(1,[{value:'<script>unsafe</script>'}]);assert.equal(app.list().children[0].textContent,'<script>unsafe</script>');
});
