const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
function script(file) {
  const text = fs.readFileSync(file, 'utf8');
  return text.slice(text.indexOf('<script>') + 8, text.indexOf('</script>'));
}
const preview = script('app/Views/workspace/trash_preview_script.php');
function thumbnail(window = {}, lazy = false) {
  const message = {textContent:''};
  const status = {hidden:false, querySelector:()=>message};
  const button = {disabled:false, querySelector:selector=>selector==='[data-trash-thumbnail]' ? image : status};
  const image = {dataset:{src:'/private/trash/preview?thumbnail=1'}, hidden:true, loading:'lazy', listeners:{},
    set src(value) { this.requestedWhileHidden = this.hidden; this.loadingAtRequest = this.loading; this.requestUrl = value; },
    get src() { return this.requestUrl; },
    closest:()=>button, addEventListener(name, fn) { this.listeners[name] = fn; }};
  const document = {addEventListener:(_,fn)=>fn(), querySelectorAll:()=>[image], getElementById:()=>null};
  let intersection, observed;
  class Observer { constructor(fn) { intersection=fn; } observe(target) { observed=target; } unobserve() {} }
  vm.runInNewContext(preview, {window,document,Set, ...(lazy ? {IntersectionObserver:Observer} : {})});
  return {window,image,button,status,message, get observed(){return observed;}, intersect(){intersection([{target:button,isIntersecting:true}]);}};
}
test('Trash lazy preview displays successful thumbnails and replaces failures with text', () => {
  const app = thumbnail();
  assert.equal(app.image.src, app.image.dataset.src);
  app.image.listeners.load();
  assert.equal(app.image.hidden, false);
  assert.equal(app.status.hidden, true);
  app.image.listeners.error();
  assert.equal(app.image.hidden, true);
  assert.equal(app.button.disabled, true);
  assert.equal(app.message.textContent, 'Preview unavailable');
});
test('Failed Trash previews are not requested again after AJAX navigation', () => {
  const app = thumbnail();
  app.image.listeners.error();
  const next = thumbnail(app.window);
  assert.equal(next.image.src, undefined);
  assert.equal(next.button.disabled, true);
});
function selection() {
  function element(dataset={}) {
    return {dataset, listeners:{}, children:[], hidden:false, disabled:false,
      classList:{toggle(){}}, addEventListener(name,fn) {this.listeners[name]=fn;},
      replaceChildren(){this.children=[];}, appendChild(child){this.children.push(child);},
      closest(){return {classList:{toggle(){}}};}};
  }
  const ids = ['trashBulkForm','trashSelectedInputs','trashSelectionCount','trashSelectionActions','selectAllTrash','trashRestoreDestination','serverRestoreFolder'];
  const nodes = Object.fromEntries(ids.map(id=>[id,element()]));
  const boxes = [
    element({trashType:'file',trashId:'1'}),
    element({trashType:'server',trashId:'abc',restoreDestination:'1'})
  ];
  const actions = [element(),element()];
  const document = {addEventListener:(_,fn)=>fn(),getElementById:id=>nodes[id],
    querySelectorAll:q=>q==='[data-trash-select]'?boxes:actions,createElement:()=>element()};
  vm.runInNewContext(script('app/Views/workspace/trash.php'),{document,Set,Array});
  return {nodes,boxes,actions};
}
test('Trash selection submits correct source IDs and only shows relevant restore destination', () => {
  const {nodes,boxes,actions}=selection();
  assert.equal(nodes.trashRestoreDestination.hidden,true);
  boxes[0].checked=true; boxes[0].listeners.change();
  assert.equal(nodes.trashSelectionCount.textContent,'1 selected');
  assert.equal(nodes.trashRestoreDestination.hidden,true);
  assert.equal(nodes.trashSelectedInputs.children[0].name,'file_ids[]');
  boxes[1].checked=true; boxes[1].listeners.change();
  assert.equal(nodes.trashRestoreDestination.hidden,false);
  assert.equal(nodes.serverRestoreFolder.disabled,false);
  assert.equal(nodes.trashSelectedInputs.children[1].name,'server_ids[]');
  assert.equal(actions.every(x=>!x.disabled),true);
  nodes.selectAllTrash.listeners.click();
  assert.equal(nodes.trashSelectionCount.textContent,'0 selected');
  assert.equal(nodes.trashSelectionActions.hidden,true);
  assert.equal(nodes.serverRestoreFolder.disabled,true);
});
test('Trash prevents empty submissions and preserves permanent-delete confirmation markup', () => {
  const {nodes}=selection(); let prevented=false;
  nodes.trashBulkForm.listeners.submit({preventDefault(){prevented=true;}});
  assert.equal(prevented,true);
  const view=fs.readFileSync('app/Views/workspace/trash.php','utf8');
  assert.match(view,/data-confirm-kind="delete"/);
  assert.match(view,/This cannot be undone/);
  assert.match(view,/csrf_field\(\)/);
});

test('Lazy Trash thumbnails observe the visible card, not the hidden image', () => {
  const app = thumbnail({}, true);
  assert.equal(app.observed, app.button);
  assert.equal(app.image.src, undefined);
  app.intersect();
  assert.equal(app.image.src, app.image.dataset.src);
});

test('Hidden thumbnails request eagerly only after their card approaches the viewport', () => {
  const app = thumbnail({}, true);
  assert.equal(app.image.src, undefined);
  app.intersect();
  assert.equal(app.image.requestedWhileHidden, true);
  assert.equal(app.image.loadingAtRequest, 'eager');
  app.image.listeners.load();
  assert.equal(app.image.hidden, false);
  assert.equal(app.status.hidden, true);
});

test('Browsers without IntersectionObserver also start hidden thumbnail requests eagerly', () => {
  const app = thumbnail();
  assert.equal(app.image.loadingAtRequest, 'eager');
  assert.equal(app.image.src, app.image.dataset.src);
});
