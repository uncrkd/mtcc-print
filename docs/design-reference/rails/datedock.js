/* Shared in-hand date dock — faithful reproduction of in-hand-by-dock-final.html
   (continuous scrolling tier calendar + pricing-tier chart). Self-injects its
   CSS + DOM and exposes window.DateDock.open({basePrice, current, onPick}).
   basePrice = the product's STANDARD-tier total; tiers scale from it.
   onPick({ label:'Tue, Jun 16', dy:'16', wd:'TUE', mult:0, tier:'standard' }). */
(function(){
  var TODAY=new Date(2026,5,9);
  var MO=['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'],
      MOL=['January','February','March','April','May','June','July','August','September','October','November','December'];
  var DOWL=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'],
      DOWS=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'], WDS=['SUN','MON','TUE','WED','THU','FRI','SAT'];
  /* established tier curve (ratios derived from the dock-final demo: 33.40/37.40/45.40/53.40/61.40/71.40) */
  var TIERS=[
    {key:'early',   name:'Best Value',        days:'10+ DAYS', ratio:0.893, lead:8, cut:'5:00 PM'},
    {key:'standard',name:'Standard',          days:'5 DAYS',   ratio:1.000, lead:4, cut:'5:00 PM'},
    {key:'3days',   name:'Rush',              days:'3 DAYS',   ratio:1.214, lead:3, cut:'5:00 PM'},
    {key:'2days',   name:'Express',           days:'2 DAYS',   ratio:1.428, lead:2, cut:'5:00 PM'},
    {key:'nextday', name:'Priority',          days:'NEXT DAY', ratio:1.642, lead:1, cut:'3:00 PM'},
    {key:'sameday', name:"We'll Get It Done", days:'TODAY',    ratio:1.909, lead:0, cut:'3:00 PM'}
  ];
  var LT={ early:['#6ee7b7','#ecfdf5','#047857'], standard:['#93c5fd','#eff6ff','#1e3a8a'], '3days':['#fde047','#fefce8','#92400e'], '2days':['#fdba74','#fff7ed','#c2410c'], nextday:['#fca5a5','#fef2f2','#7f1d1d'], sameday:['#c4b5fd','#f5f3ff','#5b21b6'] };
  var BASE=37.40, QTY=1, onPickCb=null, selTier='standard', selDate=new Date(2026,5,16);
  function ratioOf(k){ for(var i=0;i<TIERS.length;i++) if(TIERS[i].key===k) return TIERS[i].ratio; return 1; }
  function priceOf(k){ return BASE*ratioOf(k); }
  function money(n){ return '$'+(Math.round(n*100)/100).toFixed(2); }
  function moneyShort(n){ return '$'+Math.round(n).toLocaleString('en-US'); }
  function subBiz(d,n){ var x=new Date(d), c=0; while(c<n){ x.setDate(x.getDate()-1); var w=x.getDay(); if(w!==0&&w!==6) c++; } return x; }
  function bizFwd(d){ var x=new Date(TODAY), c=0; while(x<d){ x.setDate(x.getDate()+1); var w=x.getDay(); if(w!==0&&w!==6) c++; } return c; }
  function tierForDate(d){ var n=bizFwd(d); if(n<=0) return 'sameday'; if(n===1) return 'nextday'; if(n===2) return '2days'; if(n===3) return '3days'; if(n<=7) return 'standard'; return 'early'; }

  /* ---------- CSS (scoped to the dock + scrim; vars live on .cfg-swap-wrap) ---------- */
  var css=`
  .dd-scrim{ position:fixed; inset:0; background:rgba(24,14,46,.4); backdrop-filter:blur(2px); opacity:0; pointer-events:none; transition:opacity .35s; z-index:88; }
  .dd-scrim.open{ opacity:1; pointer-events:auto; }
  .cfg-swap-wrap{ font-family:'Montserrat',sans-serif; position:fixed; left:0; right:0; bottom:0; z-index:89;
    --brand:#7c3aed; --primary:#7c3aed; --primary-light:#ede9fe; --primary-dark:#5b21b6; --text:#1e1b2e; --text-2:#1a1625; --subtext:#6b7280; --muted:#9ca3af; --border:#e5e7eb; --border-light:#f3f4f6; --green-dark:#047857; --gradient-brand:linear-gradient(135deg,#7c3aed,#5b21b6); --radius-pill:999px; --weight-semi:600; --weight-bold:700; --weight-black:900; --ease:cubic-bezier(.22,1,.36,1);
    background:rgba(250,248,255,.72); backdrop-filter:blur(30px) saturate(1.7); -webkit-backdrop-filter:blur(30px) saturate(1.7); border-top:3px solid var(--brand); border-left:1px solid rgba(255,255,255,.7); border-right:1px solid rgba(255,255,255,.7); border-radius:16px 16px 0 0; box-shadow:0 -16px 54px rgba(20,10,40,.3); transform:translateY(100%); transition:transform .44s var(--ease); max-height:92vh; overflow:auto; }
  .cfg-swap-wrap.open{ transform:translateY(0); }
  .cfg-swap-close{ position:absolute; top:14px; right:18px; width:36px; height:36px; background:#f1eefb; border:1.5px solid var(--border); border-radius:50%; cursor:pointer; font-family:inherit; font-size:22px; line-height:1; color:var(--text-2); display:flex; align-items:center; justify-content:center; transition:all .15s; z-index:6; box-shadow:0 2px 6px rgba(0,0,0,.06); }
  .cfg-swap-close:hover{ background:var(--text-2); color:#fff; border-color:var(--text-2); }
  .dock-head{ display:flex; align-items:center; gap:16px; flex-wrap:wrap; padding:20px 64px 16px 28px; border-bottom:1px solid rgba(255,255,255,.6); background:rgba(255,255,255,.25); }
  .dock-head h2{ font-size:24px; font-weight:var(--weight-black); color:var(--text-2); letter-spacing:-.02em; }
  .head-div{ width:1px; height:26px; background:var(--border); flex:none; } .dock-head p{ font-size:12px; color:var(--subtext); }
  .dock-body{ padding:18px 28px 18px; }
  .main-row{ position:relative; min-height:360px; }
  .left-col{ position:absolute; left:0; top:0; width:352px; z-index:6; display:flex; flex-direction:column; }
  .right-col{ width:100%; display:flex; flex-direction:column; }
  .chart-scroll{ overflow-x:auto; cursor:default; user-select:none; -webkit-user-select:none; scrollbar-width:none; } .chart-scroll::-webkit-scrollbar{ display:none; }
  .chart-scroll.scrollable{ cursor:grab; } .chart-scroll.scrollable.dragging{ cursor:grabbing; }
  .tier-sb{ display:none; margin-left:376px; margin-top:10px; height:8px; border-radius:4px; background:rgba(120,90,180,.12); position:relative; } .tier-sb.on{ display:block; }
  .tier-thumb{ position:absolute; top:0; bottom:0; left:0; min-width:36px; border-radius:4px; background:#c4b5e8; cursor:grab; } .tier-thumb:hover{ background:#b3a0de; } .tier-thumb.dragging{ cursor:grabbing; }
  .cal-card{ border:1px solid var(--border); border-radius:11px; overflow:hidden; background:rgba(255,255,255,.92); box-shadow:12px 0 26px -10px rgba(20,10,40,.18); }
  .cal-head{ display:flex; align-items:center; justify-content:space-between; padding:8px 10px 6px; border-bottom:1px solid var(--border-light); background:#fff; }
  .cal-month-lbl{ font-size:12px; font-weight:var(--weight-bold); color:var(--text-2); }
  .cal-nav-grp{ display:flex; gap:5px; }
  .cal-nav{ width:24px; height:24px; border:1px solid var(--border); border-radius:6px; background:#fff; color:var(--text-2); font-size:13px; cursor:pointer; display:flex; align-items:center; justify-content:center; } .cal-nav:hover{ background:var(--text-2); color:#fff; border-color:var(--text-2); }
  .cal-dow{ position:sticky; top:0; z-index:3; display:grid; grid-template-columns:repeat(7,1fr); gap:4px; padding:7px 0 10px; background:#fff; font-size:9.5px; color:var(--subtext); text-align:center; text-transform:uppercase; letter-spacing:.05em; font-weight:var(--weight-bold); }
  .cal-scroll{ overflow-y:auto; max-height:307px; padding:0 10px 10px; scrollbar-gutter:stable; overscroll-behavior:contain; } .cal-scroll::-webkit-scrollbar{ width:7px; } .cal-scroll::-webkit-scrollbar-thumb{ background:#d6cdeb; border-radius:4px; }
  .cal-grid{ display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
  .cfg-cal-date{ position:relative; aspect-ratio:1; scroll-margin-top:38px; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:3px 2px; border-radius:6px; cursor:pointer; background:#fafaf8; color:#a1a1aa; border:1px solid transparent; transition:transform .1s, opacity .2s; font-family:inherit; }
  .cfg-cal-date.dim{ opacity:.3; }
  .cfg-cal-num{ font-size:12px; font-weight:var(--weight-bold); line-height:1; }
  .cfg-cal-price{ font-size:9px; font-weight:var(--weight-semi); color:var(--subtext); margin-top:2px; line-height:1; } .cfg-cal-price.cheap{ color:var(--green-dark); font-weight:var(--weight-bold); }
  .cfg-cal-date:hover:not(.past):not(.weekend){ transform:scale(1.06); }
  .cfg-cal-date.selected{ box-shadow:inset 0 0 0 2.5px var(--text-2); z-index:2; }
  .cfg-cal-date.past{ background:transparent; color:#d4d4d8; cursor:not-allowed; }
  .cfg-cal-date.weekend{ background:transparent; color:#c9bcd9; cursor:not-allowed; border:1px dashed #e2d9f0; }
  .cfg-cal-date .mtag{ position:absolute; top:-1px; left:0; right:0; font-size:6.5px; font-weight:var(--weight-black); letter-spacing:.05em; color:var(--brand); text-transform:uppercase; }
  .cfg-cal-date.tier-sameday{ background:#ede9fe; color:#5b21b6; border-color:#c4b5fd; } .cfg-cal-date.tier-nextday{ background:#fee2e2; color:#7f1d1d; border-color:#fca5a5; } .cfg-cal-date.tier-2days{ background:#ffedd5; color:#7c2d12; border-color:#fdba74; } .cfg-cal-date.tier-3days{ background:#fef9c3; color:#713f12; border-color:#fde047; } .cfg-cal-date.tier-standard{ background:#dbeafe; color:#1e3a8a; border-color:#93c5fd; } .cfg-cal-date.tier-early{ background:#dcfce7; color:#14532d; border-color:#86efac; }
  .timewrap{ margin-top:16px; padding-top:14px; border-top:1px solid var(--border-light); }
  .sec-h{ font-size:11px; font-weight:var(--weight-bold); letter-spacing:.1em; text-transform:uppercase; color:var(--subtext); margin-bottom:9px; }
  .time-chips{ display:flex; gap:8px; }
  .tc{ flex:1; text-align:center; padding:10px 6px; border:1.5px solid var(--border); border-radius:10px; background:#fff; font-family:inherit; font-size:12px; font-weight:var(--weight-semi); color:var(--subtext); cursor:pointer; } .tc.on{ border-color:var(--primary); background:var(--primary-light); color:var(--primary-dark); }
  .chart-head{ margin-bottom:4px; margin-left:376px; } .ch-title{ font-size:14px; font-weight:var(--weight-bold); color:var(--text-2); letter-spacing:-.01em; } .ch-sub{ font-size:11.5px; color:var(--subtext); margin-top:3px; }
  .cfg-pricing-chart-wrap{ display:flex; flex-direction:column; gap:6px; padding:18px 26px 0 376px; width:max-content; }
  .cfg-bracket-row,.cfg-pricing-chart,.cfg-timer-row{ display:grid; grid-template-columns:repeat(6,140px); gap:12px; justify-content:start; }
  .cfg-bracket{ display:flex; align-items:center; justify-content:center; gap:8px; font-size:10px; font-weight:var(--weight-bold); color:var(--subtext); letter-spacing:.1em; text-transform:uppercase; padding:4px 0; } .cfg-bracket span{ white-space:nowrap; }
  .cfg-bracket-arm{ flex:1; height:1px; background:#d4d4d8; position:relative; }
  .cfg-bracket-arm:first-child::before{ content:''; position:absolute; left:-4px; top:0; width:4px; height:12px; border-left:1px solid #d4d4d8; border-top:1px solid #d4d4d8; }
  .cfg-bracket-arm:last-child::after{ content:''; position:absolute; right:0; top:0; width:1px; height:12px; background:#d4d4d8; }
  .cfg-tier-card{ position:relative; background:#fff; border:1.5px solid var(--border); border-radius:10px; cursor:inherit; display:flex; flex-direction:column; font-family:inherit; text-align:center; }
  .cfg-tier-card.current{ transform:translateY(-3px); }
  .cfg-tier-card.current.tier-early{ box-shadow:0 0 0 2px #059669,0 8px 22px rgba(5,150,105,.3); } .cfg-tier-card.current.tier-standard{ box-shadow:0 0 0 2px #2563eb,0 8px 22px rgba(37,99,235,.3); } .cfg-tier-card.current.tier-3days{ box-shadow:0 0 0 2px #eab308,0 8px 22px rgba(234,179,8,.3); } .cfg-tier-card.current.tier-2days{ box-shadow:0 0 0 2px #ea580c,0 8px 22px rgba(234,88,12,.3); } .cfg-tier-card.current.tier-nextday{ box-shadow:0 0 0 2px #dc2626,0 8px 22px rgba(220,38,38,.3); } .cfg-tier-card.current.tier-sameday{ box-shadow:0 0 0 2px #7c3aed,0 8px 22px rgba(124,58,237,.3); }
  .cfg-tier-card.expired{ opacity:.45; }
  .cfg-tier-card.current::before{ content:''; position:absolute; left:-8px; top:6px; bottom:6px; border-left:1.5px dashed #c4c4c8; z-index:4; }
  .cfg-tier-card.first-later::before{ content:''; position:absolute; left:-4px; top:6px; bottom:6px; border-left:1.5px dashed #c4c4c8; z-index:4; }
  .cfg-tier-card.current:first-child::before{ display:none; }
  .cfg-tc-tag-above{ position:absolute; top:-18px; left:50%; transform:translateX(-50%); color:#fff; font-size:9.5px; font-weight:var(--weight-semi); letter-spacing:.06em; padding:4px 11px; border-radius:var(--radius-pill); white-space:nowrap; z-index:5; box-shadow:0 3px 10px rgba(0,0,0,.18); }
  .tag-early{ background:#059669; } .tag-standard{ background:#2563eb; } .tag-3days{ background:#ca8a04; } .tag-2days{ background:#ea580c; } .tag-nextday{ background:#dc2626; } .tag-sameday{ background:#7c3aed; }
  .cfg-tc-header{ border-radius:8.5px 8.5px 0 0; padding:6px 8px; font-size:10px; font-weight:var(--weight-bold); letter-spacing:.06em; text-transform:uppercase; color:#fff; text-align:center; min-height:32px; display:flex; flex-direction:column; justify-content:center; line-height:1.15; } .cfg-tc-header small{ display:block; font-size:8px; font-weight:var(--weight-semi); opacity:.75; letter-spacing:.04em; margin-top:1px; }
  .tier-early .cfg-tc-header{ background:#059669; } .tier-standard .cfg-tc-header{ background:#2563eb; } .tier-3days .cfg-tc-header{ background:#eab308; color:#3f2a04; } .tier-2days .cfg-tc-header{ background:#ea580c; } .tier-nextday .cfg-tc-header{ background:#dc2626; } .tier-sameday .cfg-tc-header{ background:#7c3aed; }
  .cfg-tc-body{ padding:0; flex:1 1 auto; display:flex; flex-direction:column; }
  .cfg-tc-band{ padding:5px 8px 6px; text-align:center; } .cfg-tc-band-orderby{ border-bottom:1px solid rgba(0,0,0,.04); } .cfg-tc-band-price{ border-top:1px solid rgba(0,0,0,.04); }
  .tier-early .cfg-tc-band{ background:#ecfdf5; color:#14532d; } .tier-standard .cfg-tc-band{ background:#eff6ff; color:#1e3a8a; } .tier-3days .cfg-tc-band{ background:#fefce8; color:#713f12; } .tier-2days .cfg-tc-band{ background:#fff7ed; color:#7c2d12; } .tier-nextday .cfg-tc-band{ background:#fef2f2; color:#7f1d1d; } .tier-sameday .cfg-tc-band{ background:#f5f3ff; color:#5b21b6; }
  .cfg-tc-order-by{ font-size:11px; text-transform:uppercase; letter-spacing:.08em; font-weight:var(--weight-semi); }
  .cfg-tc-date-block{ padding:9px 8px 11px; background:#fff; display:flex; flex-direction:column; align-items:center; flex:1 1 auto; }
  .cfg-tc-month{ font-size:11px; font-weight:var(--weight-bold); color:var(--subtext); letter-spacing:.06em; line-height:1; }
  .cfg-tc-daynum{ font-size:34px; font-weight:var(--weight-bold); color:var(--text-2); line-height:1; margin:2px 0 0; letter-spacing:-.02em; }
  .cfg-tc-dow{ font-size:11px; font-weight:var(--weight-semi); color:var(--subtext); margin-top:2px; }
  .cfg-tc-price{ font-size:19px; font-weight:var(--weight-bold); color:var(--text-2); line-height:1; letter-spacing:-.01em; }
  .cfg-tc-ppe{ font-size:10px; color:var(--subtext); font-weight:500; margin-top:3px; }
  .cfg-tc-loss{ font-size:10px; font-weight:var(--weight-semi); color:#92400e; margin-top:4px; font-style:italic; } .cfg-tc-loss.savings{ color:#166534; font-weight:var(--weight-bold); font-style:normal; }
  .cfg-tc-expired{ font-size:16px; font-weight:var(--weight-bold); color:var(--muted); letter-spacing:.1em; margin:22px 0 8px; } .cfg-expired-price{ color:var(--muted); text-decoration:line-through; }
  .cfg-tc-cutoff{ border-radius:0 0 8.5px 8.5px; padding:6px 8px; background:#fafaf8; border-top:1px solid var(--border); font-size:10px; color:var(--text-2); font-weight:var(--weight-semi); text-align:center; letter-spacing:.02em; white-space:nowrap; } .cfg-tc-cutoff b{ font-weight:var(--weight-black); color:var(--text-2); }
  .cfg-timer-row{ }
  .cfg-lock-timer-cell{ display:flex; justify-content:center; padding-top:14px; }
  .cfg-lock-timer{ display:inline-block; padding:10px 15px; border-radius:9px; border:1.5px solid var(--lt-bd,#93c5fd); font-size:12px; position:relative; background:var(--lt-bg,#eff6ff); color:var(--lt-fg,#1e3a8a); white-space:nowrap; }
  .cfg-lock-timer::before{ content:''; position:absolute; top:-9px; left:50%; transform:translateX(-50%); width:0; height:0; border-left:8px solid transparent; border-right:8px solid transparent; border-bottom:8px solid var(--lt-bd,#93c5fd); }
  .cfg-lock-timer::after{ content:''; position:absolute; top:-7px; left:50%; transform:translateX(-50%); width:0; height:0; border-left:7px solid transparent; border-right:7px solid transparent; border-bottom:7px solid var(--lt-bg,#eff6ff); }
  .cfg-lock-timer .lt-clock{ font-weight:var(--weight-black); font-variant-numeric:tabular-nums; }
  .cfg-lock-timer-cell.first-tier{ justify-content:flex-start; padding-left:0; } .cfg-lock-timer-cell.last-tier{ justify-content:flex-end; padding-right:0; }
  .cfg-lock-timer-cell.first-tier .cfg-lock-timer::before,.cfg-lock-timer-cell.first-tier .cfg-lock-timer::after{ left:70px; }
  .cfg-lock-timer-cell.last-tier .cfg-lock-timer::before,.cfg-lock-timer-cell.last-tier .cfg-lock-timer::after{ left:auto; right:70px; transform:translateX(50%); }
  .dock-actions{ display:flex; align-items:center; gap:22px; margin-top:18px; padding-top:16px; border-top:1px solid var(--border); }
  .time-block{ flex:1; } .time-block .time-chips{ max-width:340px; }
  .da-div{ width:1px; align-self:stretch; min-height:46px; background:var(--border); flex:none; }
  .done-btn{ padding:12px 34px; border:0; border-radius:10px; background:var(--gradient-brand); color:#fff; font-family:inherit; font-weight:var(--weight-bold); font-size:13.5px; cursor:pointer; box-shadow:0 6px 16px rgba(124,58,237,.28); } .done-btn:hover{ filter:brightness(1.05); }
  @media (max-width:880px){ .dock-head{ padding:16px 54px 12px 16px; } .dock-head h2{ font-size:20px; } .head-div{ display:none; } .dock-body{ padding:14px 16px 14px; } .main-row{ position:static; min-height:0; display:flex; flex-direction:column; gap:18px; } .left-col{ position:static; width:100%; } .right-col{ width:100%; } .chart-head{ margin-left:0; } .cfg-pricing-chart-wrap{ padding-left:16px; padding-right:16px; } .tier-sb{ margin-left:16px; } .dock-actions{ flex-wrap:wrap; } }
  `;
  var styleEl=document.createElement('style'); styleEl.textContent=css; document.head.appendChild(styleEl);

  /* ---------- DOM ---------- */
  var scrim=document.createElement('div'); scrim.className='dd-scrim';
  var dock=document.createElement('div'); dock.className='cfg-swap-wrap'; dock.id='ddDock';
  dock.innerHTML=`
    <button class="cfg-swap-close" type="button" aria-label="Close">&times;</button>
    <div class="dock-head"><h2>When do you need them in-hand?</h2><span class="head-div"></span><p>The further out, the cheaper — scroll or use the arrows for later dates.</p></div>
    <div class="dock-body">
      <div class="main-row">
        <div class="left-col"><div class="cal-card">
          <div class="cal-head"><span class="cal-month-lbl" id="ddMonth">June 2026</span><span class="cal-nav-grp"><button class="cal-nav" data-nav="-1" type="button" aria-label="Previous month"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg></button><button class="cal-nav" data-nav="1" type="button" aria-label="Next month"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></button></span></div>
          <div class="cal-scroll" id="ddScroll"><div class="cal-dow"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div><div class="cal-grid" id="ddGrid"></div></div>
        </div></div>
        <div class="right-col">
          <div class="chart-head"><div class="ch-title">The sooner you order, the less you pay</div><div class="ch-sub">Here's how long you can wait at each price — order by the date shown to lock it in.</div></div>
          <div class="chart-scroll"><div class="cfg-pricing-chart-wrap" id="ddChart"></div></div>
          <div class="tier-sb" id="ddSb"><div class="tier-thumb" id="ddThumb"></div></div>
        </div>
      </div>
      <div class="dock-actions">
        <div class="time-block"><div class="sec-h">What time do you need them?</div><div class="time-chips" id="ddTime"><button class="tc" type="button">By 9 AM</button><button class="tc" type="button">By 1 PM</button><button class="tc on" type="button">By 4 PM</button></div></div>
        <div class="da-div"></div>
        <button class="done-btn" id="ddDone" type="button">Confirm date</button>
      </div>
    </div>`;
  document.body.appendChild(scrim); document.body.appendChild(dock);

  var scroller=dock.querySelector('#ddScroll'), grid=dock.querySelector('#ddGrid'), monthLbl=dock.querySelector('#ddMonth'),
      chartWrap=dock.querySelector('#ddChart'), chartScroll=dock.querySelector('.chart-scroll'), tierSb=dock.querySelector('#ddSb'), tierThumb=dock.querySelector('#ddThumb');

  var START=new Date(2026,5,7);
  function buildCal(){
    var out=[];
    for(var i=0;i<182;i++){
      var d=new Date(START); d.setDate(START.getDate()+i);
      var wknd=(d.getDay()===0||d.getDay()===6), past=d<TODAY && !(d.getFullYear()===TODAY.getFullYear()&&d.getMonth()===TODAY.getMonth()&&d.getDate()===TODAY.getDate());
      var mtag=(d.getDate()===1)?'<span class="mtag">'+MO[d.getMonth()]+'</span>':'';
      if(past){ out.push('<button class="cfg-cal-date past" data-mol="'+MOL[d.getMonth()]+' '+d.getFullYear()+'"><span class="cfg-cal-num">'+mtag+d.getDate()+'</span></button>'); continue; }
      if(wknd){ out.push('<button class="cfg-cal-date weekend" data-mol="'+MOL[d.getMonth()]+' '+d.getFullYear()+'">'+mtag+'<span class="cfg-cal-num">'+d.getDate()+'</span></button>'); continue; }
      var t=tierForDate(d), p=Math.round(priceOf(t)), cheap=(t==='early')?' cheap':'', sel=(d.getMonth()===selDate.getMonth()&&d.getDate()===selDate.getDate())?' selected':'';
      out.push('<button class="cfg-cal-date tier-'+t+sel+'" data-mo="'+d.getMonth()+'" data-day="'+d.getDate()+'" data-tier="'+t+'" data-mol="'+MOL[d.getMonth()]+' '+d.getFullYear()+'">'+mtag+'<span class="cfg-cal-num">'+d.getDate()+'</span><span class="cfg-cal-price'+cheap+'">$'+p+'</span></button>');
    }
    grid.innerHTML=out.join('');
  }
  function applyDim(label){ Array.prototype.forEach.call(grid.children,function(c){ var other=c.dataset.mol&&c.dataset.mol!==label; c.classList.toggle('dim', other&&!c.classList.contains('selected')); }); }
  function updMonth(){ var sr=scroller.getBoundingClientRect(), dow=scroller.querySelector('.cal-dow'); var topY=sr.top+(dow?dow.offsetHeight:0)+4, botY=sr.bottom; var counts={}, best=null, bestN=0;
    Array.prototype.forEach.call(grid.children,function(c){ var r=c.getBoundingClientRect(); if(r.bottom<=topY||r.top>=botY) return; var m=c.dataset.mol; if(!m) return; counts[m]=(counts[m]||0)+1; if(counts[m]>bestN){ bestN=counts[m]; best=m; } });
    if(best){ monthLbl.textContent=best; applyDim(best); } }
  var _raf; scroller.addEventListener('scroll',function(){ if(_raf) return; _raf=requestAnimationFrame(function(){ _raf=0; updMonth(); }); });
  function scrollToCell(cell,smooth){ var idx=Array.prototype.indexOf.call(grid.children,cell), row=Math.floor(idx/7); var pitch=(grid.children[7].offsetTop-grid.children[0].offsetTop)||48; scroller.scrollTo({ top:Math.max(0,row*pitch), behavior:smooth?'smooth':'auto' }); setTimeout(updMonth, smooth?420:60); }
  function navMonth(dir){ var parts=monthLbl.textContent.split(' '), mi=MOL.indexOf(parts[0]), yr=+parts[1]; mi+=dir; if(mi<0){ mi=11; yr--; } if(mi>11){ mi=0; yr++; } var target=MOL[mi]+' '+yr, cells=grid.querySelectorAll('.cfg-cal-date'); for(var i=0;i<cells.length;i++){ if(cells[i].dataset.mol===target){ scrollToCell(cells[i],true); break; } } }
  var wTarget=null, wRaf=0;
  function wAnim(){ if(wTarget===null){ wRaf=0; return; } var cur=scroller.scrollTop, d=wTarget-cur; if(Math.abs(d)<0.5){ scroller.scrollTop=wTarget; wTarget=null; wRaf=0; return; } scroller.scrollTop=cur+d*0.2; wRaf=requestAnimationFrame(wAnim); }
  scroller.addEventListener('wheel',function(e){ e.preventDefault(); var dy=e.deltaY; if(e.deltaMode===1) dy*=18; else if(e.deltaMode===2) dy*=scroller.clientHeight; var max=scroller.scrollHeight-scroller.clientHeight; if(wTarget===null) wTarget=scroller.scrollTop; wTarget=Math.max(0,Math.min(max,wTarget+dy)); if(!wRaf) wRaf=requestAnimationFrame(wAnim); },{passive:false});

  function renderChart(inHand, curKey){
    var curIdx=-1; for(var k=0;k<TIERS.length;k++){ if(TIERS[k].key===curKey){ curIdx=k; break; } } if(curIdx<0) curIdx=1;
    var curTotal=priceOf(TIERS[curIdx].key), hasLater=curIdx<TIERS.length-1;
    var laterCount=TIERS.length-1-curIdx, bText=laterCount<=1?'Price if you wait':'Prices if you order later';
    var bracket=hasLater?'<div class="cfg-bracket-row"><div class="cfg-bracket" style="grid-column:'+(curIdx+2)+' / -1"><div class="cfg-bracket-arm"></div><span>'+bText+'</span><div class="cfg-bracket-arm"></div></div></div>':'<div class="cfg-bracket-row" style="height:24px"></div>';
    var cards=TIERS.map(function(t,i){
      var price=priceOf(t.key), ob=subBiz(inHand,t.lead), expired=ob<TODAY; if(i<curIdx) expired=true;
      if(expired) return '<button class="cfg-tier-card tier-'+t.key+' expired" disabled><div class="cfg-tc-header">'+t.name+'<small>'+t.days+'</small></div><div class="cfg-tc-body"><div class="cfg-tc-expired">EXPIRED</div><div class="cfg-tc-price cfg-expired-price">'+money(price)+'</div><div class="cfg-tc-ppe">not enough lead time</div></div><div class="cfg-tc-cutoff">&nbsp;</div></button>';
      var isCur=(i===curIdx), hint;
      if(isCur && hasLater){ var sv=Math.round(priceOf(TIERS[curIdx+1].key)-curTotal); hint=sv>0?'<div class="cfg-tc-loss savings">YOU SAVE $'+sv+' TODAY</div>':'<div class="cfg-tc-ppe">'+(QTY>1?money(price/QTY)+'/ea':'&nbsp;')+'</div>'; }
      else hint='<div class="cfg-tc-loss">+$'+Math.round(price-curTotal)+' more if you wait</div>';
      var tag=isCur?'<div class="cfg-tc-tag-above tag-'+t.key+'">YOUR PRICE TODAY</div>':'';
      return '<button type="button" class="cfg-tier-card tier-'+t.key+(isCur?' current':'')+((i===curIdx+1)?' first-later':'')+'" data-tier="'+t.key+'">'+tag+'<div class="cfg-tc-header">'+t.name+'<small>'+t.days+'</small></div><div class="cfg-tc-body"><div class="cfg-tc-band cfg-tc-band-orderby"><div class="cfg-tc-order-by">Order by</div></div><div class="cfg-tc-date-block"><div class="cfg-tc-month">'+MO[ob.getMonth()]+'</div><div class="cfg-tc-daynum">'+ob.getDate()+'</div><div class="cfg-tc-dow">'+DOWL[ob.getDay()]+'</div></div><div class="cfg-tc-band cfg-tc-band-price"><div class="cfg-tc-price">'+money(price)+'</div>'+hint+'</div></div><div class="cfg-tc-cutoff">Cut-off <b>'+t.cut+' (EST)</b></div></button>';
    }).join('');
    var lt=LT[curKey]||LT.standard, save=hasLater?Math.round(priceOf(TIERS[curIdx+1].key)-curTotal):0, savePre=save>0?'<b>Save $'+save+'</b> &mdash; ':'';
    var timer='<div class="cfg-timer-row"><div class="cfg-lock-timer-cell" data-ci="'+curIdx+'" style="grid-column:'+(curIdx+1)+'"><div class="cfg-lock-timer" style="--lt-bd:'+lt[0]+';--lt-bg:'+lt[1]+';--lt-fg:'+lt[2]+'"><span>'+savePre+'<b>'+TIERS[curIdx].name+'</b> rate ends in </span><span class="lt-clock">5h 12m 04s</span></div></div></div>';
    chartWrap.innerHTML=bracket+'<div class="cfg-pricing-chart">'+cards+'</div>'+timer;
    setTimerAnchor();
  }
  function commit(){
    if(!onPickCb) return;
    var w=selDate.getDay(), moA=MO[selDate.getMonth()], T=null; for(var i=0;i<TIERS.length;i++){ if(TIERS[i].key===selTier) T=TIERS[i]; }
    var tEl=dock.querySelector('#ddTime .tc.on'); var time=tEl?tEl.textContent.replace(/^By\s+/i,'').toLowerCase().replace(/\s+/g,''):'4pm';
    onPickCb({ label: DOWS[w]+', '+moA.charAt(0)+moA.slice(1).toLowerCase()+' '+selDate.getDate(), labelFull: DOWS[w]+', '+MOL[selDate.getMonth()]+' '+selDate.getDate(), dy:String(selDate.getDate()), wd:WDS[w], wdFull:DOWL[w].toUpperCase(), mo:moA, mult:ratioOf(selTier)-1, tier:selTier, tierLabel:(T?(T.name+' · '+T.days):'STANDARD').toUpperCase(), time:time });
  }
  function pickDate(btn){
    Array.prototype.forEach.call(grid.querySelectorAll('.cfg-cal-date.selected'),function(c){ c.classList.remove('selected'); });
    btn.classList.add('selected');
    selDate=new Date(2026,+btn.dataset.mo,+btn.dataset.day); selTier=btn.dataset.tier;
    renderChart(selDate, selTier);
    var ml=btn.dataset.mol; if(ml){ monthLbl.textContent=ml; applyDim(ml); }
    requestAnimationFrame(function(){ scrollToActive(true); });
    commit();   /* auto-confirm: apply on every date selection — no Confirm click needed */
  }
  grid.addEventListener('click', function(e){ var b=e.target.closest('.cfg-cal-date'); if(!b||b.classList.contains('past')||b.classList.contains('weekend')) return; pickDate(b); });
  chartWrap.addEventListener('click', function(e){ var c=e.target.closest('.cfg-tier-card'); if(!c||c.disabled||!c.dataset.tier) return;
    var cell=grid.querySelector('.cfg-cal-date.tier-'+c.dataset.tier+':not(.past):not(.weekend)'); if(cell){ cell.scrollIntoView({block:'nearest'}); pickDate(cell); } });
  Array.prototype.forEach.call(dock.querySelectorAll('.cal-nav'),function(b){ b.addEventListener('click', function(){ navMonth(+b.dataset.nav); }); });
  dock.querySelector('#ddTime').addEventListener('click', function(e){ var t=e.target.closest('.tc'); if(!t) return; Array.prototype.forEach.call(this.children,function(x){ x.classList.remove('on'); }); t.classList.add('on'); commit(); });

  function setTimerAnchor(){ var cell=chartScroll.querySelector('.cfg-lock-timer-cell'); if(!cell) return; var ci=+cell.dataset.ci, last=TIERS.length-1; cell.classList.remove('first-tier','last-tier'); if(chartScroll.classList.contains('scrollable')) cell.classList.add('first-tier'); else if(ci===0) cell.classList.add('first-tier'); else if(ci===last) cell.classList.add('last-tier'); }
  function syncSb(){ var sc=chartScroll, max=sc.scrollWidth-sc.clientWidth; if(max<=1){ tierSb.classList.remove('on'); sc.classList.remove('scrollable'); setTimerAnchor(); return; } tierSb.classList.add('on'); sc.classList.add('scrollable'); var trackW=tierSb.clientWidth, thumbW=Math.max(36, trackW*sc.clientWidth/sc.scrollWidth); tierThumb.style.width=thumbW+'px'; tierThumb.style.left=((sc.scrollLeft/max)*(trackW-thumbW))+'px'; setTimerAnchor(); }
  function scrollToActive(smooth){ var sc=chartScroll; var max=sc.scrollWidth-sc.clientWidth; if(max<=1) return; var active=sc.querySelector('.cfg-tier-card.current'); if(!active) return; var wrap=sc.querySelector('.cfg-pricing-chart-wrap'); var pl=wrap?(parseFloat(getComputedStyle(wrap).paddingLeft)||0):0; var curX=active.getBoundingClientRect().left - sc.getBoundingClientRect().left; sc.scrollTo({ left:Math.max(0,Math.min(max, sc.scrollLeft+(curX-pl))), behavior:smooth?'smooth':'auto' }); }
  chartScroll.addEventListener('scroll',syncSb); window.addEventListener('resize',syncSb);
  (function(){ var down=false,sx=0,sl=0; chartScroll.addEventListener('pointerdown',function(e){ if(!chartScroll.classList.contains('scrollable')) return; down=true; sx=e.clientX; sl=chartScroll.scrollLeft; chartScroll.classList.add('dragging'); try{chartScroll.setPointerCapture(e.pointerId);}catch(_){}; }); chartScroll.addEventListener('pointermove',function(e){ if(!down) return; chartScroll.scrollLeft=sl-(e.clientX-sx); }); function end(){ down=false; chartScroll.classList.remove('dragging'); } chartScroll.addEventListener('pointerup',end); chartScroll.addEventListener('pointercancel',end); })();
  (function(){ var down=false,sx=0,sl=0; tierThumb.addEventListener('pointerdown',function(e){ e.stopPropagation(); down=true; sx=e.clientX; sl=chartScroll.scrollLeft; tierThumb.classList.add('dragging'); try{tierThumb.setPointerCapture(e.pointerId);}catch(_){}; }); tierThumb.addEventListener('pointermove',function(e){ if(!down) return; var sc=chartScroll,max=sc.scrollWidth-sc.clientWidth,trackW=tierSb.clientWidth,thumbW=tierThumb.offsetWidth; sc.scrollLeft=sl+(e.clientX-sx)*(max/(trackW-thumbW)); }); function end(){ down=false; tierThumb.classList.remove('dragging'); } tierThumb.addEventListener('pointerup',end); tierThumb.addEventListener('pointercancel',end); })();

  function close(){ dock.classList.remove('open'); scrim.classList.remove('open'); }
  dock.querySelector('.cfg-swap-close').addEventListener('click', close);
  scrim.addEventListener('click', close);
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') close(); });
  dock.querySelector('#ddDone').addEventListener('click', function(){ commit(); close(); });

  window.DateDock={ open:function(opts){ opts=opts||{}; BASE=opts.basePrice>0?opts.basePrice:37.40; QTY=opts.qty||1; onPickCb=opts.onPick||null;
      // resolve current selection (default Jun 16)
      selDate=new Date(2026,5,16); selTier='standard';
      if(opts.current){ var mm=String(opts.current).match(/([A-Za-z]{3})\s+(\d+)|(\d+)/); var dnum=mm?+(mm[2]||mm[3]):16; var moIdx=5; if(mm&&mm[1]){ var mi=MO.indexOf(mm[1].toUpperCase()); if(mi>=0) moIdx=mi; } selDate=new Date(2026,moIdx,dnum); selTier=tierForDate(selDate); }
      buildCal(); renderChart(selDate, selTier);
      scrim.classList.add('open'); dock.classList.add('open');
      var s=grid.querySelector('.cfg-cal-date.selected');
      setTimeout(function(){ if(s){ try{ s.scrollIntoView({block:'center'}); }catch(e){} var ml=s.dataset.mol||'June 2026'; monthLbl.textContent=ml; applyDim(ml); } syncSb(); scrollToActive(false); },80);
    }, close:close };
})();
