<style>
#main.trash-workspace, #main.trash-folder-workspace { margin-top: 60px; padding-top: 28px; --trash-primary: #0d6efd; --trash-muted: #526075; }
#main.trash-workspace .trash-hero { padding: 1.5rem; background: linear-gradient(135deg, #f0f6ff, #fff); border-color: #dbe7f7; }
#main.trash-workspace .trash-hero-icon { background: #e7f1ff; color: #0d6efd; }
#main.trash-workspace .trash-stat { border-color: #dbe7f7; }
#main.trash-workspace .trash-hero p { max-width: 75ch; line-height: 1.6; color: #526075; font-size: .9rem; }
#main.trash-workspace .trash-grid, .trash-folder-workspace .item-grid { grid-template-columns: repeat(auto-fill, minmax(min(300px, 100%), 1fr)); gap: 1.25rem; padding: 1.25rem; }
#main.trash-workspace .trash-item.is-selected { border-color: #0d6efd; box-shadow: 0 0 0 3px #0d6efd30; background: #f5f9ff; }
#main.trash-workspace .trash-select { width: 1.15rem; height: 1.15rem; margin: 0; }
#main.trash-workspace .trash-select:checked { background-color: #0d6efd; border-color: #0d6efd; }
#main.trash-workspace .trash-preview, .trash-folder-workspace .item-preview { height: 200px; position: relative; }
#main.trash-workspace button.trash-preview:disabled:hover::after { display: none; }
.trash-media-preview img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain !important; background: #edf0f4; }
.trash-media-preview img[hidden] { display: none; }
.trash-preview-status { display: flex; align-items: center; justify-content: center; flex-direction: column; gap: .5rem; font-size: .8rem; color: #526075; padding: 1rem; }
.trash-preview-status[hidden] { display: none; }
.trash-preview-status i { font-size: 2.2rem; }
.trash-video-mark { position: absolute; bottom: .6rem; left: .6rem; background: #192438df; color: white; border-radius: 6px; padding: .2rem .5rem; font-size: .75rem; }
#main.trash-workspace .trash-type { top: 173px; font-size: .7rem; }
#main.trash-workspace .trash-content { padding: 1rem 1.1rem 1.2rem; gap: .2rem; }
#main.trash-workspace .trash-name, .trash-folder-workspace .item-name { font-size: 1rem; line-height: 1.5; }
#main.trash-workspace .trash-meta, .trash-folder-workspace .item-meta { color: #526075; font-size: .8rem; overflow-wrap: anywhere; }
#main.trash-workspace .trash-reason { font-size: .8rem; color: #526075; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-height: 0; }
#main.trash-workspace .retention-row { flex-wrap: wrap; gap: .3rem; }
#main.trash-workspace .retention-row span { color: #526075; font-size: .75rem; }
#main.trash-workspace .trash-filter .btn-primary, #main.trash-workspace .trash-selection-actions .btn-outline-success { background: #0d6efd; border-color: #0d6efd; color: white; }
#main.trash-workspace .trash-filter .btn-primary:hover, #main.trash-workspace .trash-selection-actions .btn-outline-success:hover { background: #0b5ed7; border-color: #0a58ca; }
#main.trash-workspace .trash-selection-actions .btn-outline-danger { border-color: #0d6efd; color: #0d6efd; background: white; }
#main.trash-workspace .trash-selection-actions .btn-outline-danger:hover { background: #0b5ed7; border-color: #0a58ca; color: white; }
#main.trash-workspace .trash-bulk-count { color: #084298; background: #e7f1ff; }
#main.trash-workspace .trash-bulk-toolbar { background: #f7faff; padding: 1rem; gap: .8rem; }
#main.trash-workspace .trash-destination { flex-basis: 100%; border-top: 1px solid #dbe7f7; padding-top: 1rem; order: 5; }
#main.trash-workspace .trash-destination[hidden] { display: none; }
#main.trash-workspace .trash-destination input { max-width: 480px; }
.trash-preview-body, .trash-folder-preview-body { position: relative; }
.trash-modal-status { color: white; text-align: center; padding: 2rem; }
#main.trash-workspace button:focus-visible, .trash-folder-workspace button:focus-visible { outline: 3px solid #0d6efd; outline-offset: 3px; }
@media (max-width: 575px) {
    #main.trash-workspace, #main.trash-folder-workspace { padding: 20px 12px; margin-top: 60px; }
    #main.trash-workspace .trash-grid, .trash-folder-workspace .item-grid { padding: .75rem; }
    #main.trash-workspace .trash-hero { padding: 1rem; }
    #main.trash-workspace .trash-selection-actions { width: 100%; }
    #main.trash-workspace .trash-selection-actions button { flex: 1; min-height: 42px; }
    #main.trash-workspace .trash-filter .row > div { width: 100%; }
    #main.trash-workspace .trash-hero-icon { display: none; }
}
</style>
