// ════════════════════════════════════════════════════════════════════
// EEIS — Excel Master Sync (Student Database 2025-2026, 7 July 2026)
// Source: OneDrive Excel "student database 2025-2026" + "Left 25-26" tabs
//
// HOW TO RUN:
//   1. Open https://madrasah.eeis.store and sign in
//   2. Wait for students to load
//   3. Open console (Cmd+Option+J), paste this whole script, press Enter
//
// WHAT IT DOES:
//   • Adds missing payments for 16 students (as instalment records)
//   • Removes the 5 students on the Excel "Left 25-26" tab
//   • Saves to Google Sheets
// SAFE TO RE-RUN — skips anything already applied.
// ════════════════════════════════════════════════════════════════════
(function(){
'use strict';
if(typeof db==='undefined'||!db.length){console.error('❌ Sign in and wait for students to load first.');return;}
const nrm=s=>String(s||'').replace(/[*~^?]/g,' ').replace(/\s+/g,' ').trim().toLowerCase();
const findIdx=(fn,sn)=>db.findIndex(s=>nrm(s['First Name'])===nrm(fn)&&nrm(s['Surname'])===nrm(sn));

const UPDATES=[{"fn": "Zain", "sn": "Mudasir", "addPaid": 130, "method": "Card payment TAAAXKF6HXK", "paydate": "06/09/2025 ,16/05/26", "receipt": "52 & 65"}, {"fn": "Eesa", "sn": "Iqbal", "addPaid": 260, "method": "Online store #154", "paydate": "2026-05-16 00:00:00", "receipt": ""}, {"fn": "Muhammad Iqrash", "sn": "Humas", "addPaid": 48, "method": "Mum set up a Give a Little Direct Debit £24 a mont", "paydate": "2025-09-08 00:00:00", "receipt": ""}, {"fn": "Safin", "sn": "Ahmed", "addPaid": 230, "method": "Card payment TAAAXXH93HE & Sumup  Online store #15", "paydate": "04/10/2025 & 09/05/2026 & 6/7/26", "receipt": "7, #238"}, {"fn": "Yakoob", "sn": "Subhan", "addPaid": 260, "method": "Online", "paydate": "06/07/2026 & 07/07/26", "receipt": "#236 and 240"}, {"fn": "Mohammed Saihan", "sn": "Alam", "addPaid": 130, "method": "Online store #133 & #151", "paydate": "12/12/2025 & 08/05/2026", "receipt": ""}, {"fn": "Muhammad Yahya", "sn": "Ali", "addPaid": 130, "method": "Bank transfer- check statement", "paydate": "30/12/2025 & 6/07/26", "receipt": "#235"}, {"fn": "Yayha", "sn": "Subhan", "addPaid": 260, "method": "Online", "paydate": "06/07/2026 & 07/07/26", "receipt": "#236 and 240"}, {"fn": "Zayan", "sn": "Ahmed", "addPaid": 130, "method": "Card ref TAAAYTEACLN", "paydate": "06/12/2025 & 4/7/26", "receipt": "24234"}, {"fn": "Layla", "sn": "Calloo", "addPaid": 130, "method": "Online 134 & 182", "paydate": "29/12/2025 & 4/06/26", "receipt": ""}, {"fn": "Labiba", "sn": "Akter", "addPaid": 260, "method": "Online store Order #230", "paydate": "2026-06-13 00:00:00", "receipt": ""}, {"fn": "Anamta", "sn": "Omar", "addPaid": 260, "method": "Online", "paydate": "2026-07-06 00:00:00", "receipt": "#237"}, {"fn": "Dina Ariana", "sn": "Iqbal", "addPaid": 260, "method": "Online store #154", "paydate": "2026-05-16 00:00:00", "receipt": ""}, {"fn": "Zakyya", "sn": "Abdul", "addPaid": 265, "method": "Card sumup ref TAAA2ZC9NM4", "paydate": "2026-05-09 00:00:00", "receipt": "36"}, {"fn": "Samairah", "sn": "Dinally", "addPaid": 260, "method": "Bank Transfer  ", "paydate": "15 /08/26 & 31/12/25", "receipt": ""}, {"fn": "Tanisha", "sn": "Kyolaba", "addPaid": 100, "method": "Card", "paydate": "2026-05-02 00:00:00", "receipt": "64"}];
const REMOVALS=[{"fn": "Cherno Abdullahi", "sn": "Barry"}, {"fn": "Abdul Karim", "sn": "Barry"}, {"fn": "Raffay", "sn": "Murtaza"}, {"fn": "Sola", "sn": "Imran"}, {"fn": "Isra", "sn": "Imran"}];

let changed=0;
console.log('🔄 Excel Master Sync starting… db has',db.length,'students');

for(const u of UPDATES){
  const i=findIdx(u.fn,u.sn);
  if(i<0){console.warn('⚠️ Not found in app:',u.fn,u.sn);continue;}
  const s=db[i];
  if(u.addPaid){
    s.instalments=s.instalments||[];
    const already=s.instalments.some(x=>x.notes&&x.notes.includes('Excel sync 07/07/26')&&Math.abs(parseFloat(x.amount)-u.addPaid)<0.5);
    if(already){console.log('⏩ Already synced:',u.fn,u.sn);}
    else{
      s.instalments.push({date:(u.paydate&&/^\d{4}-/.test(u.paydate))?u.paydate:'2026-07-07',
        amount:u.addPaid,method:(u.method||'See Excel').slice(0,40),receipt:u.receipt||'',
        ref:'Excel sync',notes:'Excel sync 07/07/26'+(u.paydate&&!/^\d{4}-/.test(u.paydate)?' — paid: '+u.paydate:''),
        addedBy:'Excel Sync',addedAt:new Date().toISOString(),edited:false,editHistory:[]});
      console.log('➕ '+u.fn+' '+u.sn+': +£'+u.addPaid+' ('+(u.method||'?')+')');changed++;
    }
  }
  if(u.setDue!==undefined&&parseFloat(s['Fees Due'])!==u.setDue){s['Fees Due']=u.setDue;console.log('✏️ '+u.fn+' '+u.sn+' Fees Due → £'+u.setDue);changed++;}
  if(u.setClass&&s['Class']!==u.setClass){s['Class']=u.setClass;console.log('✏️ '+u.fn+' '+u.sn+' Class → '+u.setClass);changed++;}
}

console.log('\n── Removing students on "Left 25-26" tab ──');
for(const r of REMOVALS){
  const i=findIdx(r.fn,r.sn);
  if(i<0){console.log('⏩ Already removed:',r.fn,r.sn);continue;}
  db.splice(i,1);
  console.log('🗑 Removed: '+r.fn+' '+r.sn+' (left the Madrasah — still recorded in Excel Left tab)');changed++;
}

console.log('\n⚠️ MANUAL REVIEW: Salma Joosub — app shows £260 paid, Excel shows £210. Not changed. Please check which is right.');

if(changed>0){
  filtered=[...db];
  try{renderDashboard();applyFilters();}catch(e){}
  console.log('\n💾 Saving '+changed+' changes to Google Sheets…');
  saveDb().then(()=>console.log('✅ Sync complete — web app now matches the Excel master.'))
          .catch(e=>console.error('❌ Save failed:',e.message));
} else console.log('ℹ️ Nothing to change — already in sync.');
})();
