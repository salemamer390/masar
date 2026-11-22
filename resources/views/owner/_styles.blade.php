<style>
.owner-page{max-width:1800px;margin:auto}
.owner-heading{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px}
.owner-heading h1{font-size:24px;margin:0;font-weight:800}
.owner-heading h1 i{color:#1268f3;margin-inline-end:8px}
.owner-heading p{margin:4px 0 0;color:#71809c;font-size:12px}
.owner-private-badge{display:inline-flex;align-items:center;gap:6px;background:#0f1e33;color:#7fd0ff;border-radius:20px;padding:6px 14px;font-size:11px;font-weight:800;white-space:nowrap}
.owner-tabs{display:flex;gap:6px;background:#fff;border:1px solid #e5ebf3;border-radius:12px;padding:7px;margin-bottom:16px;box-shadow:0 8px 24px #2047780e;overflow:auto}
.owner-tab{flex:1;min-width:max-content;border:1px solid transparent;border-radius:8px;padding:11px 13px;background:transparent;color:#60708c;font:700 12px Cairo,Tahoma,sans-serif;cursor:pointer}
.owner-tab.active,.owner-tab:hover{background:#eaf2ff;border-color:#d6e6ff;color:#1268f3}
a.owner-tab{text-decoration:none;text-align:center;display:flex;align-items:center;justify-content:center;gap:6px}
.owner-tab b{background:#ffebcf;color:#a96300;border-radius:20px;padding:1px 6px;font-size:10px}
.owner-panel[hidden]{display:none}
.owner-stats{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:10px;margin-bottom:15px}
.owner-stat{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #e9eff7;border-radius:12px;padding:13px;box-shadow:0 8px 24px #2047780e}
.owner-stat>i{width:42px;height:42px;display:grid;place-items:center;border-radius:11px;font-size:21px;flex-shrink:0}
.owner-stat span,.owner-stat small{display:block;font-size:11px;color:#53627c}
.owner-stat strong{display:block;font-size:21px}
.owner-stat small{font-size:10px;color:#0ca66a}
.owner-stat.green>i{background:#dff8eb;color:#0ca66a}
.owner-stat.blue>i{background:#e2efff;color:#1672f8}
.owner-stat.amber>i{background:#fff2d8;color:#df8b00}
.owner-stat.purple>i{background:#f0e4ff;color:#8b39e8}
.owner-stat.rose>i{background:#ffe4e7;color:#f34d59}
.owner-stat.teal>i{background:#dcf6f3;color:#0d9488}
.owner-stat.slate>i{background:#e8edf5;color:#52627a}
.owner-overview-grid,.owner-notification-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
.owner-card{background:#fff;border:1px solid #e7edf5;border-radius:12px;box-shadow:0 8px 24px #2047780e;overflow:hidden;margin-bottom:14px}
.owner-card header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid #edf1f6}
.owner-card h2{font-size:14px;margin:0}
.owner-card h2 i{color:#1268f3;margin-inline-end:6px}
.distribution-list{padding:17px}
.distribution-list>div{margin-bottom:13px}
.distribution-list>div>span{font-size:11px;color:#53627c}
.distribution-list b{float:left;font-size:12px}
.distribution-bar{height:7px;border-radius:10px;background:#edf2f7;clear:both;margin-top:6px;overflow:hidden}
.distribution-bar i{display:block;height:100%;background:#1268f3;border-radius:10px}
.overview-list>div{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 16px;border-bottom:1px solid #edf1f6}
.overview-list>div:last-child{border-bottom:0}
.overview-list b,.overview-list small{display:block}
.overview-list small{color:#71809c;font-size:10px;margin-top:3px}
.owner-status{display:inline-flex;border-radius:20px;padding:4px 9px;font-size:10px;font-weight:700;white-space:nowrap;background:#edf1f5;color:#60708c}
.owner-status.active{background:#ddf8e9;color:#09975d}
.owner-status.trial{background:#e3f1ff;color:#1670d7}
.owner-status.suspended,.owner-status.expired,.owner-status.rejected{background:#ffe4e6;color:#d92d43}
.owner-status.pending{background:#fff2d8;color:#bd7600}
.owner-status.approved{background:#ddf8e9;color:#09975d}
.owner-card form{padding:16px}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 12px}
.form-grid label{display:flex;flex-direction:column;gap:5px;font-size:11px;font-weight:700;color:#344461}
.form-grid input,.form-grid select,.form-grid textarea{width:100%;border:1px solid #dfe7f1;border-radius:7px;padding:9px 10px;font:inherit;outline:none}
.full{grid-column:1/-1}
.table-card{margin-bottom:14px}
.table-scroll{overflow:auto;padding:0 10px 10px}
.table-scroll table{width:100%;border-collapse:separate;border-spacing:0;white-space:nowrap;font-size:11px}
.table-scroll th{background:#f4f7fb;color:#53627c;padding:10px;text-align:right}
.table-scroll td{padding:9px;border-bottom:1px solid #edf1f6;color:#293a59}
.table-tools{display:flex;gap:8px;padding:10px 12px}
.table-tools input,.table-tools select,.search-input{border:1px solid #dfe7f1;border-radius:8px;padding:9px 12px;font:inherit;outline:none}
.section-toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.section-toolbar h2{font-size:18px;margin:0}
.section-toolbar p{font-size:12px;color:#71809c;margin:3px 0}
.help-card ul{margin:0;padding:18px 35px;line-height:2;color:#53627c}
.muted{color:#8795a8}
.center{text-align:center}
.inline-form{display:inline-block;padding:0!important;margin-inline-end:4px}
.owner-update-grid{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(280px,.8fr);gap:14px;align-items:start}
.update-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:16px;border-bottom:1px solid #edf1f6}
.update-summary>div{background:#f6f8fb;border:1px solid #e7edf5;border-radius:10px;padding:12px;text-align:center}
.update-summary span{display:block;color:#60708c;font-size:11px;margin-bottom:5px}
.update-summary strong{font-size:20px;color:#17243c}
.next-update{margin:16px 16px 0;padding:12px 14px;border-radius:10px;background:#eaf2ff;border:1px solid #d5e5ff}
.next-update span{display:block;color:#4f6b98;font-size:11px;margin-bottom:6px}
.next-update code{display:block;direction:ltr;text-align:left;color:#0f55bc;font-weight:700;overflow-wrap:anywhere}
.pending-list-wrap{padding:14px 16px;border-bottom:1px solid #edf1f6}
.pending-list-wrap h3{font-size:13px;margin:0 0 10px}
.pending-migration-list{margin:0;padding-inline-start:22px;max-height:210px;overflow:auto}
.pending-migration-list li{padding:5px 0;color:#53627c}
.pending-migration-list code{direction:ltr;display:inline-block;font-size:11px;overflow-wrap:anywhere}
.blocked-list-wrap{background:#fffafb}
.blocked-migration-list li{color:#7d4350}
.blocked-reason{display:block;color:#b04a5d;font-size:10px;margin-top:2px}
.database-update-form{padding:16px;display:grid;gap:12px}
.database-update-form>label:not(.check-row){display:flex;flex-direction:column;gap:5px;font-size:11px;font-weight:700;color:#344461}
.database-update-form input[type="password"]{width:100%;border:1px solid #dfe7f1;border-radius:7px;padding:9px 10px;font:inherit;outline:none}
.check-row{display:flex;align-items:flex-start;gap:9px;font-size:12px;font-weight:700;color:#344461;line-height:1.7}
.check-row input{width:17px;height:17px;margin-top:2px;accent-color:#1268f3;flex-shrink:0}
.field-error{display:block;color:#cf2942;font-size:11px;font-weight:700;margin-top:3px}
.database-update-error{background:#fff0f2;color:#ba2038;border:1px solid #ffd0d7;border-radius:9px;padding:10px 12px;font-size:12px;font-weight:700}
.database-update-form .btn{justify-content:center}
.update-complete,.update-unavailable{display:flex;gap:12px;align-items:flex-start;margin:16px;padding:15px;border-radius:11px}
.update-complete{background:#e9fbf2;border:1px solid #c8f0dc;color:#0a7b4d}
.update-unavailable{background:#fff7e5;border:1px solid #f2d69a;color:#8d5c00}
.update-complete>i,.update-unavailable>i{font-size:23px;margin-top:2px}
.update-complete p,.update-unavailable p{margin:4px 0 0;color:inherit;font-size:12px;line-height:1.7}
.readiness-list{list-style:none!important;margin:0!important;padding:12px!important;display:grid;gap:8px}
.readiness-list li{display:grid;grid-template-columns:22px minmax(0,1fr) auto;gap:9px;align-items:start;background:#f7f9fc;border:1px solid #e7edf5;border-radius:9px;padding:10px;line-height:1.5}
.readiness-list li>i{font-size:17px;margin-top:2px}
.readiness-list strong{display:block;color:#24334f;font-size:12px}
.readiness-list small{display:block;color:#6c7a93;font-size:10px;margin-top:2px}
.readiness-list li>span{font-size:10px;font-weight:800;border-radius:999px;padding:3px 8px}
.readiness-ok>i{color:#14935f}.readiness-ok>span{background:#e3f8ef;color:#0b7b4e}
.readiness-warning>i{color:#d99616}.readiness-warning>span{background:#fff1cf;color:#8d5c00}
.readiness-failed>i{color:#d23b52}.readiness-failed>span{background:#ffe4e9;color:#ad243b}
.readiness-empty{padding:0 18px;color:#6c7a93;font-size:12px}
.readiness-note{margin:0 14px 12px;padding:10px 12px;border-radius:9px;background:#edf5ff;border:1px solid #d7e7fb;color:#345f8f;font-size:11px;line-height:1.7}
.help-subtitle{display:flex;align-items:center;gap:7px;margin:8px 18px 0;color:#24334f;font-size:14px}
.recovery-steps{margin:10px 0 0;padding-inline-start:22px;font-size:12px;line-height:1.8}
@media(max-width:1400px){.owner-stats{grid-template-columns:repeat(4,1fr)}}
@media(max-width:1000px){.owner-stats{grid-template-columns:repeat(3,1fr)}}
@media(max-width:850px){.owner-stats,.owner-overview-grid,.owner-notification-grid,.owner-update-grid{grid-template-columns:1fr}.owner-heading{align-items:flex-start;flex-direction:column}}
@media(max-width:600px){.owner-stats{grid-template-columns:repeat(2,1fr)}.form-grid{grid-template-columns:1fr}.full{grid-column:auto}.owner-tab{flex:0 0 auto}}
</style>
<style>
.company-actions{white-space:nowrap}.company-actions .btn{margin-inline-start:4px}
.company-delete-btn{background:#fee2e2;color:#b91c1c;border:1px solid #fecaca}
.company-details{display:grid;grid-template-columns:1fr 1fr;gap:10px 24px;margin:0}
.company-details dt{font-size:12px;color:#778;font-weight:400}.company-details dd{margin:0;overflow-wrap:anywhere;word-break:break-word}
@media(max-width:640px){.company-details{grid-template-columns:1fr}}
</style>
