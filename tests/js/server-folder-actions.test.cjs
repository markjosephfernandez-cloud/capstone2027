const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

test('folder move uses the form URL even when its action field shadows form.action',async()=>{
 const nodes={};
 function node(id){return nodes[id] ||= {dataset:{},attrs:{},events:{},classList:{toggle(){}},value:'',disabled:false,
  addEventListener(k,fn){this.events[k]=fn;},getAttribute(k){return this.attrs[k]??null;},
  setAttribute(k,v){this.attrs[k]=v;},removeAttribute(k){delete this.attrs[k];},querySelector(){return null;}};}
 const form=node('folderActionForm');
 form.attrs.action='/workspace/server-library/folders/selection';
 form.action=node('folderActionKind');form.action.value='move';
 node('folderMoveDestination').value='Archive';
 node('toggleFolderSelection').querySelector=()=>({textContent:''});
 const requests=[],completed=[];
 vm.runInNewContext(fs.readFileSync('public/assets/js/server-folder-actions.js','utf8'),{
  document:{getElementById:node,querySelectorAll:()=>[]},
  window:{location:{href:'http://localhost/library'},GreyhoundFolderMove:{isBusy:()=>false,start:()=>((...args)=>completed.push(args))}},
  bootstrap:{Modal:{getInstance:()=>({hide(){}})}},
  FormData:class {constructor(value){assert.equal(value,form);}},
  fetch:async(url,options)=>{requests.push({url,options});return {ok:true,redirected:false,headers:{get:()=> 'application/json'},json:async()=>({ok:true,message:'Selected folders moved.'})};}
 });
 let prevented=false;
 await form.events.submit({preventDefault(){prevented=true;}});
 assert.equal(prevented,true);
 assert.equal(requests.length,1);
 assert.equal(requests[0].url,'/workspace/server-library/folders/selection');
 assert.equal(requests[0].options.method,'POST');
 assert.deepEqual(completed,[[true,'Selected folders moved.']]);
 assert.equal(form.attrs['aria-busy'],undefined);
});
