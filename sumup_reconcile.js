// ════════════════════════════════════════════════════════════════════
// EEIS Al-Kauthar Madrasah — SumUp Payment Reconciliation
// Generated: 2026-05-20
// 
// INSTRUCTIONS:
//   1. Open https://madrasah.eeis.store in your browser
//   2. Sign in with your Super Admin account
//   3. Wait for the student database to load (you should see students listed)
//   4. Open browser DevTools (F12 on desktop / Cmd+Option+J on Mac)
//   5. Click the "Console" tab
//   6. Paste this ENTIRE script and press Enter
//   7. Watch the output — it will log results then auto-save to Sheets
//
// WHAT IT DOES:
//   • Matches 134 SumUp fee payments (Sep 2023 – May 2026) to students
//   • Adds each as a historical instalment record (date, amount, method, SumUp ref)
//   • Skips any payment already recorded (same date + amount)
//   • Logs a gap report: which students have NO 2025-26 payment recorded
//   • Saves everything to Google Sheets via saveDb()
//
// SAFE TO RE-RUN: duplicate-safe — won't double-count existing records
// ════════════════════════════════════════════════════════════════════

(function(){
'use strict';
if(typeof db==='undefined'||!db.length){
  console.error('❌ db not found — sign in to the EEIS dashboard first, wait for students to load, then re-run.');
  return;
}
console.log('🔄 EEIS SumUp Reconciliation starting… db has',db.length,'students');

const P=[
  {o:'#10',d:'2023-10-05',e:'darryljlucas@gmail.com',c:'Mr Darryl J Lucas',a:270.0,q:2,h:false,m:'Card',n:'',l:'Full Year'},
  {o:'#100',d:'2025-09-06',e:'saqibparacha@hotmail.com',c:'Saqib Paracha',a:150.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year (new student)'},
  {o:'#101',d:'2025-09-06',e:'shakil9463@gmail.com',c:'Shakil Mahmud',a:260.0,q:1,h:false,m:'Card',n:'',l:'Full Year'},
  {o:'#102',d:'2025-09-07',e:'umar215@yahoo.com',c:'Umar Nasir',a:260.0,q:1,h:false,m:'Card',n:'Ayesha Umar - G2 Girls',l:'Full Year'},
  {o:'#103',d:'2025-09-07',e:'madebyfarha@gmail.com',c:'Farha Ahmed for Amelia Ahmed student',a:260.0,q:1,h:false,m:'Card',n:'',l:'Full Year'},
  {o:'#104',d:'2025-09-08',e:'saifrehman478@gmail.com',c:'Sameer Rahman',a:130.0,q:1,h:true,m:'Card',n:'Sameer rahman. (B4 )',l:'Half Year'},
  {o:'#105',d:'2025-09-08',e:'saifrehman478@gmail.com',c:'Labibah rahman',a:130.0,q:1,h:true,m:'Card',n:'Labibah rahman (G3)',l:'Half Year'},
  {o:'#106',d:'2025-09-08',e:'amirrahim3@gmail.com',c:'Zaki Abdul Rahim',a:130.0,q:1,h:true,m:'Card',n:'Zaki Abdul Rahim - half year fees to January 2026',l:'Half Year'},
  {o:'#107',d:'2025-09-08',e:'saima_r_mahmood@hotmail.com',c:'Saima Mahmood',a:150.0,q:2,h:true,m:'Card',n:'Inaya Tufail G4\nAyaan Tufail B2',l:'Half Year (new student)'},
  {o:'#108',d:'2025-09-09',e:'faycalrekkam@yahoo.co.uk',c:'Faycal Rekkam',a:260.0,q:2,h:false,m:'Card',n:'',l:'Full Year'},
  {o:'#109',d:'2025-09-11',e:'nrchoudhury@outlook.com',c:'Neelum Choudhury',a:280.0,q:1,h:false,m:'Card',n:'Payment for my daughter Miriam Choudhury',l:'Full Year (new student)'},
  {o:'#11',d:'2023-10-06',e:'alom@hotmail.co.uk',c:'Alom Hussain',a:125.0,q:2,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#110',d:'2025-09-11',e:'rid-1@hotmail.co.uk',c:'Ameerbeg Zayn',a:130.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#111',d:'2025-09-12',e:'darryljlucas@gmail.com',c:'Mr Darryl Lucas',a:260.0,q:2,h:false,m:'Card',n:'Dawud Lucas\nDanyal Lucas',l:'Full Year'},
  {o:'#112',d:'2025-09-12',e:'calloozainab@gmail.com',c:'Zainab Calloo',a:150.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year (new student)'},
  {o:'#113',d:'2025-09-12',e:'surumiharris@gmail.com',c:'Surumi Harish',a:280.0,q:1,h:false,m:'Card',n:'Amreen Harish,\n22 Temple road,\nEpsom,\nKt19 8HA',l:'Full Year (new student)'},
  {o:'#114',d:'2025-09-12',e:'samiafaizan@hotmail.com',c:'Samia  Faizan',a:150.0,q:1,h:true,m:'Card',n:'',l:'Half Year (new student)'},
  {o:'#115',d:'2025-09-12',e:'e_akmez@hotmail.co.uk',c:'Muhammad Akmez Edoo',a:260.0,q:1,h:false,m:'Card',n:'Student name: Aleena Bibi Zahrah Edoo',l:'Full Year'},
  {o:'#116',d:'2025-09-12',e:'zakculasy@gmail.com',c:'Zakir Culasy',a:150.0,q:1,h:true,m:'Apple Pay',n:'This is for my daughter to attend, Zoya Zakir Culasy',l:'Half Year (new student)'},
  {o:'#117',d:'2025-09-13',e:'sophieboodhoo@gmail.com',c:'Sophie Boodhoo',a:260.0,q:1,h:false,m:'Apple Pay',n:'',l:'Full Year'},
  {o:'#118',d:'2025-09-13',e:'sarfraz125@gmail.com',c:'Sarfraz Nawaz',a:150.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year (new student)'},
  {o:'#119',d:'2025-09-13',e:'nasirmahmed@gmail.com',c:'Nasir Ahmed',a:130.0,q:2,h:true,m:'Card',n:'For Soha Ahmed\nand Khazeena Ahmed',l:'Half Year'},
  {o:'#12',d:'2023-10-07',e:'bux.anisa@gmail.com',c:'Anisa bux',a:125.0,q:1,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#120',d:'2025-09-15',e:'roya.lms509@gmail.com',c:'Mastura Sultanzai',a:130.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#121',d:'2025-09-18',e:'robinaakhtar677@hotmail.com',c:'Robina Akhtar',a:130.0,q:2,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#124',d:'2025-09-27',e:'alom@hotmail.co.uk',c:'Alom Hussain',a:260.0,q:2,h:false,m:'Apple Pay',n:'',l:'Full Year'},
  {o:'#125',d:'2025-10-05',e:'nrchoudhury@outlook.com',c:'Neelum Choudhury',a:280.0,q:1,h:false,m:'Card',n:'Madrasah fees for Jonah Choudhury',l:'Full Year (new student)'},
  {o:'#126',d:'2025-10-20',e:'shahadat_englnd@yahoo.co.uk',c:'Shahadat hossain',a:280.0,q:1,h:false,m:'Apple Pay',n:'',l:'Full Year (new student)'},
  {o:'#129',d:'2025-11-29',e:'amanuddin76@gmail.com',c:'Aman Uddin',a:260.0,q:1,h:false,m:'Card',n:'',l:'Full Year'},
  {o:'#13',d:'2023-10-14',e:'shakil9463@gmail.com',c:'Shakil Mahmud',a:225.0,q:1,h:false,m:'Card',n:'',l:'Full Year (online)'},
  {o:'#130',d:'2025-11-30',e:'tengur@yahoo.com',c:'Mohammad Tengur',a:260.0,q:1,h:false,m:'Card',n:'Ayaana Tengur',l:'Full Year'},
  {o:'#131',d:'2025-12-02',e:'alexa.tidy@hotmail.co.uk',c:'Mrs Alexa Tidy',a:130.0,q:2,h:true,m:'Card',n:'Abdul Karim Barry\nCherno Abdullahi Barry \n6 month fee.',l:'Half Year'},
  {o:'#132',d:'2025-12-12',e:'robinaakhtar677@hotmail.com',c:'Robina Akhtar',a:130.0,q:2,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#133',d:'2025-12-12',e:'rip4k@hotmail.co.uk',c:'Raul Alam',a:130.0,q:1,h:true,m:'Card',n:'Saihaan Alam. B3. Madrassa half year Fee.',l:'Half Year'},
  {o:'#134',d:'2025-12-29',e:'calloozainab@gmail.com',c:'Zainab Calloo',a:130.0,q:2,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#135',d:'2026-01-09',e:'saima_r_mahmood@hotmail.com',c:'Saima Mahmood',a:130.0,q:2,h:true,m:'Card',n:'Inaya Tufail - G4\nAyaan Tufail - B3',l:'Half Year'},
  {o:'#136',d:'2026-01-10',e:'roya.lms509@gmail.com',c:'Mastura Sultanzai',a:130.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#137',d:'2026-01-17',e:'shawalimugula@gmail.vom',c:'Shawali Mugula',a:260.0,q:1,h:false,m:'Card',n:'',l:'Full Year'},
  {o:'#138',d:'2026-02-07',e:'amanuddin76@gmail.com',c:'Habiba Akter',a:260.0,q:1,h:false,m:'Card',n:'',l:'Full Year'},
  {o:'#139',d:'2026-02-07',e:'amirrahim3@gmail.com',c:'Zaki Abdul Rahim and Zayan Abdul Rahim',a:130.0,q:2,h:true,m:'Apple Pay',n:'Fees for \n\n1) Zaki Abdul Rahim\n2) Zayan Abdul Rahim',l:'Half Year'},
  {o:'#14',d:'2023-10-15',e:'e_akmez@hotmail.co.uk',c:'muhammad edoo',a:225.0,q:1,h:false,m:'Card',n:'student Aleena Edoo Q1 class',l:'Full Year (online)'},
  {o:'#140',d:'2026-02-07',e:'nasirmahmed@gmail.com',c:'Nasir Ahmed',a:130.0,q:2,h:true,m:'Apple Pay',n:'Khazeena Ahmed\nSoha Ahmed',l:'Half Year'},
  {o:'#141',d:'2026-02-08',e:'madiha.urooj@outlook.com',c:'Madiha Urooj',a:130.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#142',d:'2026-02-09',e:'aly_youssef1987@yahoo.com',c:'Aly Abdelkarim',a:130.0,q:1,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#143',d:'2026-02-11',e:'aqibparacha@hotmail.com',c:'Aariz Mohammed Paracha',a:130.0,q:1,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#144',d:'2026-02-14',e:'zakculasy@gmail.com',c:'Zakir Culasy',a:130.0,q:1,h:true,m:'Apple Pay',n:'Zoya Zakir Culasy',l:'Half Year'},
  {o:'#149',d:'2026-04-11',e:'rid-1@hotmail.co.uk',c:'Ameerbeg Ridwan',a:130.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#15',d:'2023-10-29',e:'nabeel_sheraz@yahoo.com',c:'Nabeel Sheraz',a:125.0,q:1,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#151',d:'2026-05-08',e:'rip4k@hotmail.co.uk',c:'Saihaan Alam.  Madrassa Fee.',a:130.0,q:1,h:true,m:'Google Pay',n:'',l:'Half Year'},
  {o:'#152',d:'2026-05-09',e:'nipa181@yahoo.com',c:'Shamrin Nahar',a:130.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#154',d:'2026-05-16',e:'jamil_786@hotmail.com',c:'Jamil Iqbal',a:260.0,q:2,h:false,m:'Google Pay',n:'',l:'Full Year'},
  {o:'#16',d:'2023-10-29',e:'nabeel_sheraz@yahoo.com',c:'Nabeel Sheraz',a:125.0,q:1,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#17',d:'2024-01-09',e:'mansurkhan@hotmail.com',c:'Mansur khan',a:250.0,q:1,h:false,m:'Card',n:'',l:'Full Year'},
  {o:'#18',d:'2024-01-16',e:'mastuarsultanzai@yahoo.com',c:'Mastura Sultanzai',a:125.0,q:2,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#19',d:'2024-01-16',e:'adg1027@gmail.com',c:'Andrew Griffiths',a:125.0,q:1,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#2',d:'2023-09-10',e:'s.mooneegan@googlemail.com',c:'Sharina Burtally',a:145.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#20',d:'2024-03-09',e:'zubairadam@hotmail.com',c:'Zubair Adam',a:125.0,q:1,h:true,m:'Card',n:'Student :Aariz Adam',l:'Half Year'},
  {o:'#21',d:'2024-03-30',e:'hussainrosin@yahoo.com',c:'Monaf Rosin',a:225.0,q:2,h:false,m:'Card',n:'',l:'Full Year (online)'},
  {o:'#22',d:'2024-04-12',e:'faycalrekkam@yahoo.co.uk',c:'Faycal Rekkam',a:250.0,q:1,h:false,m:'Apple Pay',n:'',l:'Full Year'},
  {o:'#23',d:'2024-04-13',e:'drshezz@gmail.com',c:'Shahzad Ahmed',a:225.0,q:1,h:false,m:'Card',n:'',l:'Full Year (online)'},
  {o:'#24',d:'2024-04-15',e:'delweruk@gmail.com',c:'Dalwar Hussain',a:125.0,q:1,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#24',d:'2024-04-15',e:'delweruk@gmail.com',c:'Dalwar Hussain',a:100.0,q:1,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#25',d:'2024-04-20',e:'amirrahim3@gmail.com',c:'Zaki Abdul Rahim',a:125.0,q:1,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#27',d:'2024-05-17',e:'norahnalugo@yahoo.com',c:'Norah Nalugo',a:225.0,q:1,h:false,m:'Card',n:'',l:'Full Year (online)'},
  {o:'#29',d:'2024-06-09',e:'rashidnjogeza@yahoo.com',c:'Sheba njogeza',a:125.0,q:1,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#3',d:'2023-09-12',e:'calloozainab@gmail.com',c:'Zainab calloo',a:125.0,q:1,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#30',d:'2024-06-09',e:'alom@hotmail.co.uk',c:'Alom Hussain',a:125.0,q:2,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#31',d:'2024-06-09',e:'dobos.0422@hotmail.com',c:'Karim Shahin',a:250.0,q:1,h:false,m:'Apple Pay',n:'',l:'Full Year'},
  {o:'#32',d:'2024-06-13',e:'calloozainab@gmail.com',c:'Zainab calloo',a:125.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#33',d:'2024-06-25',e:'bailor@hotmail.co.uk',c:'Bailor Barry',a:125.0,q:2,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#34',d:'2024-06-26',e:'umar215@yahoo.com',c:'Umar Nasir',a:100.0,q:1,h:true,m:'Card',n:'For daughter: Ayesha Umar\nClass: G2\nRestarted when Onsite Opened: 20th April till 27th July',l:'Half Year'},
  {o:'#36',d:'2024-07-03',e:'nabeel_sheraz@yahoo.com',c:'Nabeel Sheraz',a:100.0,q:2,h:true,m:'Google Pay',n:'Aoa, this payment is for below students,\nInshirah Nabeel - G4\nManaal Nabeel - G2',l:'Half Year'},
  {o:'#37',d:'2024-07-14',e:'nasirmahmed@gmail.com',c:'Khazeena and Soha ahmed',a:225.0,q:1,h:false,m:'Apple Pay',n:'',l:'Full Year (online)'},
  {o:'#38',d:'2024-07-20',e:'sarfarz125@gmail.com',c:'Sarfraz Nawaz',a:125.0,q:2,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#39',d:'2024-08-31',e:'umar215@yahoo.com',c:'Umar Nasir',a:250.0,q:1,h:false,m:'Card',n:'For AYESHA UMAR 2024-2025 full year',l:'Full Year'},
  {o:'#4',d:'2023-09-14',e:'shahy5000@hotmail.com',c:'mohamed shahin',a:250.0,q:1,h:false,m:'Card',n:'',l:'Full Year'},
  {o:'#40',d:'2024-09-01',e:'darryljlucas@gmail.com',c:'Mr Darryl Lucas',a:250.0,q:2,h:false,m:'Google Pay',n:'',l:'Full Year'},
  {o:'#41',d:'2024-09-02',e:'mohammadboodhoo@gmail.com',c:'Mohammad Boodhoo',a:125.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#42',d:'2024-09-03',e:'sariaawais@hotmail.com',c:'Saria Omar',a:250.0,q:1,h:false,m:'Card',n:'',l:'Full Year'},
  {o:'#43',d:'2024-09-09',e:'tarek@wahab.me.uk',c:'Tarek Wahab',a:125.0,q:2,h:true,m:'Google Pay',n:'',l:'Half Year'},
  {o:'#44',d:'2024-09-11',e:'saima_r_mahmood@hotmail.com',c:'Saima Mahmood',a:125.0,q:2,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#45',d:'2024-09-13',e:'faycalrekkam@yahoo.co.uk',c:'Faycal Rekkam',a:250.0,q:2,h:false,m:'Card',n:'',l:'Full Year'},
  {o:'#46',d:'2024-09-14',e:'robinaakhtar677@hotmail.com',c:'Robina Akhtar',a:125.0,q:2,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#47',d:'2024-09-14',e:'zubairadam@hotmail.com',c:'Zubair Adam',a:250.0,q:1,h:false,m:'Card',n:'Student Name: Aariz Adam',l:'Full Year'},
  {o:'#48',d:'2024-09-15',e:'shakil9463@gmail.com',c:'Shakil Mahmud',a:250.0,q:1,h:false,m:'Card',n:'',l:'Full Year'},
  {o:'#49',d:'2024-09-15',e:'mastuarsultanzai@yahoo.com',c:'Mastura Sultanzai',a:125.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#5',d:'2023-09-16',e:'amirrahim3@gmail.com',c:'Zaki Abdul Rahim',a:125.0,q:1,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#50',d:'2024-09-16',e:'afzalahmed007@hotmail.co.uk',c:'Afzal Ahmed',a:125.0,q:1,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#52',d:'2024-09-16',e:'bux.anisa@gmail.com',c:'ANISA Bux',a:125.0,q:1,h:true,m:'Card',n:'Alayna Razvi',l:'Half Year'},
  {o:'#53',d:'2024-09-21',e:'miakhtar80@gmail.com',c:'Muhammad Akhtar',a:250.0,q:1,h:false,m:'Card',n:'',l:'Full Year'},
  {o:'#54',d:'2024-09-28',e:'hussainrosin@yahoo.com',c:'Monaf Rosin',a:250.0,q:2,h:false,m:'Card',n:'Aliyah and Amirah Rosin',l:'Full Year'},
  {o:'#55',d:'2024-09-28',e:'amirrahim3@gmail.com',c:'Zaki Abdul Rahim',a:125.0,q:1,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#56',d:'2024-09-30',e:'e_akmez@hotmail.co.uk',c:'Muhammad Akmez Edoo',a:250.0,q:1,h:false,m:'Card',n:'',l:'Full Year'},
  {o:'#57',d:'2024-10-26',e:'rid-1@hotmail.co.uk',c:'Ridwan Ameerbeg',a:125.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#59',d:'2024-12-09',e:'alom@hotmail.co.uk',c:'Alom Hussain',a:125.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#6',d:'2023-09-19',e:'ahsanali@hotmail.co.uk',c:'Rana Ahsan Ali',a:125.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#60',d:'2024-12-19',e:'bailor@hotmail.co.uk',c:'Bailor Barry',a:125.0,q:2,h:true,m:'Card',n:'Fees for Cherno and Karim Barry',l:'Half Year'},
  {o:'#61',d:'2025-01-18',e:'saima_r_mahmood@hotmail.com',c:'Saima Mahmood',a:125.0,q:2,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#62',d:'2025-01-20',e:'mastuarsultanzai@yahoo.com',c:'Mastura Sultanzai',a:125.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#63',d:'2025-01-26',e:'alom@hotmail.co.uk',c:'Alom Hussain',a:125.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#68',d:'2025-02-02',e:'amirrahim3@gmail.com',c:'Zaki Abdul Rahim',a:125.0,q:1,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#69',d:'2025-02-07',e:'hussainrosin@yahoo.com',c:'Monaf Rosin',a:250.0,q:1,h:false,m:'Card',n:'',l:'Full Year'},
  {o:'#7',d:'2023-09-19',e:'ahsanali@hotmail.co.uk',c:'Rana Ahsan Ali',a:125.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#70',d:'2025-02-07',e:'afzalahmed007@hotmail.co.uk',c:'Afzal Ahmed',a:125.0,q:1,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#71',d:'2025-02-28',e:'robinaakhtar677@hotmail.com',c:'Robina Akhtar',a:125.0,q:2,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#72',d:'2025-03-29',e:'rid-1@hotmail.co.uk',c:'Ridwan Ameerbeg(Zayn Ameerbeg)',a:250.0,q:2,h:false,m:'Apple Pay',n:'',l:'Full Year'},
  {o:'#73',d:'2025-04-04',e:'calloozainab@gmail.com',c:'Zainab calloo',a:125.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#74',d:'2025-04-04',e:'maderbocus786@gmail.com',c:'Hussain Maderbocus',a:125.0,q:1,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#75',d:'2025-05-15',e:'jamil_786@hotmail.com',c:'Jamil Iqbal',a:250.0,q:2,h:false,m:'Google Pay',n:'',l:'Full Year'},
  {o:'#76',d:'2025-06-27',e:'rfadarkhan@icloud.com',c:'Reza Fadarkhan',a:250.0,q:1,h:false,m:'Apple Pay',n:'',l:'Full Year'},
  {o:'#77',d:'2025-06-28',e:'sarfraz125@gmail.com',c:'Sarfraz Nawaz',a:250.0,q:1,h:false,m:'Apple Pay',n:'',l:'Full Year'},
  {o:'#78',d:'2025-06-30',e:'farumas@hotmail.co.uk',c:'Faruma Subhan',a:250.0,q:1,h:false,m:'Apple Pay',n:'',l:'Full Year'},
  {o:'#79',d:'2025-06-30',e:'sadia.mutz@gmail.com',c:'Sadia Murtaza',a:125.0,q:1,h:true,m:'Card',n:'',l:'Half Year'},
  {o:'#8',d:'2023-09-29',e:'mastuarsultanzai@yahoo.com',c:'Mastura Sultanzai',a:125.0,q:2,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#80',d:'2025-07-02',e:'alexa.tidy@hotmail.co.uk',c:'Mrs Alexa Tidy',a:250.0,q:1,h:false,m:'Apple Pay',n:'Ref: Abdul Karim Barry\nCherno Abdullahi Barry',l:'Full Year'},
  {o:'#81',d:'2025-07-02',e:'maderbocus786@gmail.com',c:'Hussain Maderbocus',a:125.0,q:1,h:true,m:'Card',n:'Aaisha Maderbocus',l:'Half Year'},
  {o:'#82',d:'2025-07-02',e:'nabeel_sheraz@yahoo.com',c:'Nabeel Sheraz',a:250.0,q:2,h:false,m:'Google Pay',n:'Madrasah Fees for the year Sep 2024 - July 2025 for the student below,\nInshirah Nabeel G4\nManaal Nabeel G2',l:'Full Year'},
  {o:'#83',d:'2025-07-02',e:'sophieboodhoo@gmail.com',c:'Sophie Boodhoo',a:125.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#84',d:'2025-07-03',e:'ybeegun@yahoo.co.uk',c:'Adam Beegun',a:250.0,q:1,h:false,m:'Card',n:'Adam Beegun',l:'Full Year'},
  {o:'#85',d:'2025-07-03',e:'ahsanali@hotmail.co.uk',c:'Muhammad Yahya Ali',a:250.0,q:1,h:false,m:'Apple Pay',n:'',l:'Full Year'},
  {o:'#86',d:'2025-07-04',e:'nasirmahmed@gmail.com',c:'Khazeena/Soha Ahmed',a:250.0,q:1,h:false,m:'Card',n:'',l:'Full Year'},
  {o:'#87',d:'2025-07-04',e:'haroon.ashraf080@gmail.com',c:'Haroon Ashraf',a:250.0,q:1,h:false,m:'Card',n:'',l:'Full Year'},
  {o:'#88',d:'2025-07-05',e:'calloozainab@gmail.com',c:'Zainab Calloo',a:125.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#89',d:'2025-07-26',e:'sarfraz125@gmail.com',c:'Sarfraz Nawaz',a:250.0,q:1,h:false,m:'Apple Pay',n:'Islam Ahmed . Mohammed sarfraz was already paid',l:'Full Year'},
  {o:'#9',d:'2023-09-30',e:'saiqa.adam@gmail.com',c:'Saiqa Adam',a:125.0,q:1,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#90',d:'2025-09-04',e:'s.mooneegan@googlemail.com',c:'Sharina Burtally',a:130.0,q:2,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#91',d:'2025-09-04',e:'hissainrosin@yahoo.com',c:'Monaf Rosin',a:260.0,q:3,h:false,m:'Apple Pay',n:'',l:'Full Year'},
  {o:'#93',d:'2025-09-04',e:'s.mooneegan@googlemail.com',c:'Sharina Burtally',a:130.0,q:2,h:true,m:'Apple Pay',n:'',l:'Half Year'},
  {o:'#94',d:'2025-09-04',e:'adhammmahmoud@gmail.com',c:'Adham Attya',a:260.0,q:1,h:false,m:'Card',n:'Khaled Attya (01/10/2018)',l:'Full Year'},
  {o:'#95',d:'2025-09-04',e:'miakhtar80@gmail.com',c:'Imran Akhtar',a:260.0,q:1,h:false,m:'Card',n:'Sarina Akhtar',l:'Full Year'},
  {o:'#96',d:'2025-09-05',e:'mxxdy@hotmail.com',c:'Mahmudul Hussain',a:260.0,q:1,h:false,m:'Card',n:'',l:'Full Year'},
  {o:'#97',d:'2025-09-05',e:'c_halimah@hotmail.com',c:'Halimah Mooneegan',a:280.0,q:1,h:false,m:'Card',n:'Riyad Mooneegan',l:'Full Year (new student)'},
  {o:'#98',d:'2025-09-06',e:'amirrahim3@gmail.com',c:'Zayan Abdul Rahim',a:150.0,q:1,h:true,m:'Card',n:'',l:'Half Year (new student)'},
  {o:'#99',d:'2025-09-06',e:'aqibparacha@hotmail.com',c:'Aqib Paracha',a:150.0,q:1,h:true,m:'Card',n:'',l:'Half Year (new student)'}
];
// Email aliases (same parent, different emails across years)
const ALIAS={
  'mastuarsultanzai@yahoo.com':'roya.lms509@gmail.com',
  'hissainrosin@yahoo.com':'hussainrosin@yahoo.com',
  'sarfarz125@gmail.com':'sarfraz125@gmail.com',
  'saqibparacha@hotmail.com':'aqibparacha@hotmail.com',
  'shawalimugula@gmail.vom':'shawalimugula@gmail.com',
  'c_halimah@hotmail.com':'s.mooneegan@googlemail.com'
};

function ne(e){e=(e||'').trim().toLowerCase();return ALIAS[e]||e;}

// Build email → [studentIdx] map
const eMap={};
db.forEach((s,i)=>{
  [s['Mother Email'],s['Father Email']].forEach(raw=>{
    const e=ne(raw);
    if(e){eMap[e]=eMap[e]||[];if(!eMap[e].includes(i))eMap[e].push(i);}
  });
});

// Parse student names from Notes
function parseNames(notes){
  if(!notes)return[];
  let t=notes
    .replace(/fees for[:\s]*/gi,'').replace(/payment for (my )?(son|daughter)[:\s]*/gi,'')
    .replace(/student name[:\s]*/gi,'').replace(/student[:\s]*/gi,'')
    .replace(/ref[:\s]*/gi,'').replace(/\b(half year|full year|madrassa|madrasah)[^a-z]*/gi,'')
    .replace(/\([\w\s\/]+\)/g,'').replace(/\d{2}\/\d{2}\/\d{4}/g,'');
  const lines=t.split(/\n|\r|\d+\)|\band\b/).map(l=>l.trim()).filter(l=>l.length>3);
  const out=[];
  for(const ln of lines){
    // Strip trailing class codes like "G4", "B3", "Q1"
    const m=ln.match(/^([A-Za-z][A-Za-z'\-\s]+?)(?:\s*[-–]\s*[A-Za-z]\d.*|$)/);
    if(m){const n=m[1].trim();if(n.split(/\s+/).length>=2&&n.length<40)out.push(n.toLowerCase());}
  }
  return out;
}

function matchByName(parsedNames,idxs){
  const res={};
  for(const nm of parsedNames){
    const parts=nm.split(/\s+/);
    const fn=parts[0];
    for(const i of idxs){
      const s=db[i];
      const sfn=(s['First Name']||'').toLowerCase();
      const ssn=(s['Surname']||'').toLowerCase();
      if(sfn===fn||sfn.startsWith(fn)||(sfn+' '+ssn).includes(fn)){
        if(!Object.values(res).includes(i)){res[nm]=i;break;}
      }
    }
  }
  return res;
}

function isDup(s,date,amt){
  return(s.instalments||[]).some(i=>i.date===date&&Math.abs(parseFloat(i.amount)-amt)<0.5);
}

function makeInst(p,amt){
  return{date:p.d,amount:amt,method:p.m,receipt:'',ref:p.o+' (SumUp)',
    notes:p.l+' — SumUp import',addedBy:'SumUp Import',
    addedAt:new Date().toISOString(),edited:false,editHistory:[]};
}

const stats={matched:0,added:0,dup:0,unmatched:0,warnings:[]};
const touched=new Set();
const seenOrders=new Set(); // for dup-order detection

for(const p of P){
  const email=ne(p.e);
  const orderKey=email+'|'+p.d+'|'+p.a;
  
  // Detect duplicate orders same day same amount same email
  if(seenOrders.has(orderKey)){
    console.warn('⚡ Possible duplicate order skipped:',p.o,p.d,p.e,'£'+p.a+'×'+p.q);
    continue;
  }
  seenOrders.add(orderKey);
  
  let idxs=eMap[email]||[];
  
  // Name fallback if no email match
  if(!idxs.length){
    const custParts=p.c.toLowerCase().split(/\s+/).filter(w=>w.length>2);
    idxs=db.reduce((acc,s,i)=>{
      const names=[s['Mother Name'],s['Father Name']].join(' ').toLowerCase();
      if(custParts.length>=2&&custParts.slice(0,2).every(w=>names.includes(w)))acc.push(i);
      return acc;
    },[]);
    if(!idxs.length){
      stats.unmatched++;
      console.log('❓ UNMATCHED:',p.o,p.d,p.e,'"'+p.c+'"','£'+p.a+'×'+p.q);
      continue;
    }
  }
  
  stats.matched++;
  const parsedNames=parseNames(p.n);
  const nameMap=parsedNames.length?matchByName(parsedNames,idxs):{};
  const namedIdxs=[...new Set(Object.values(nameMap))];
  
  let assign=[]; // [{idx,amt}]
  
  if(p.q===1){
    // Single child — try to identify which one
    if(namedIdxs.length){
      assign=[{idx:namedIdxs[0],amt:p.a}];
    } else if(idxs.length===1){
      assign=[{idx:idxs[0],amt:p.a}];
    } else {
      // Multiple siblings, no name hint — use year context
      // Check which children don't yet have a payment near this date (within 3 months)
      const d=new Date(p.d);
      const noNearPmt=idxs.filter(i=>{
        const s=db[i];
        return!(s.instalments||[]).some(inst=>{
          const id=new Date(inst.date||'');
          return Math.abs((id-d)/(86400000*90))<1; // within ~90 days
        });
      });
      const target=noNearPmt.length?noNearPmt[0]:idxs[0];
      assign=[{idx:target,amt:p.a}];
      if(idxs.length>1)stats.warnings.push(p.o+' qty=1 but '+idxs.length+' siblings — assigned to '+db[target]['First Name']+' '+db[target]['Surname']);
    }
  } else {
    // Multiple children
    if(namedIdxs.length>=p.q){
      assign=namedIdxs.slice(0,p.q).map(i=>({idx:i,amt:p.a}));
    } else if(namedIdxs.length>0){
      assign=namedIdxs.map(i=>({idx:i,amt:p.a}));
      const rest=idxs.filter(i=>!namedIdxs.includes(i));
      for(let j=0;j<p.q-namedIdxs.length&&j<rest.length;j++)assign.push({idx:rest[j],amt:p.a});
    } else {
      // No names — split across idxs up to qty
      const targets=idxs.slice(0,p.q);
      assign=targets.map(i=>({idx:i,amt:p.a}));
      if(targets.length<p.q)stats.warnings.push(p.o+' qty='+p.q+' but only '+targets.length+' students found for email '+p.e);
    }
  }
  
  for(const {idx,amt} of assign){
    const s=db[idx];
    touched.add(idx);
    if(isDup(s,p.d,amt)){
      // Enhance existing record with SumUp ref if missing
      const ex=(s.instalments||[]).find(i=>i.date===p.d&&Math.abs(parseFloat(i.amount)-amt)<0.5);
      if(ex&&!ex.ref){ex.ref=p.o+' (SumUp)';ex.notes=(ex.notes||'')+' [SumUp import]';}
      stats.dup++;
      continue;
    }
    s.instalments=s.instalments||[];
    s.instalments.push(makeInst(p,amt));
    s.instalments.sort((a,b)=>(a.date||'').localeCompare(b.date||''));
    stats.added++;
  }
}

// ── Gap Report ──────────────────────────────────────────────
const CYS='2025-09-01', CYE='2026-07-31';

console.log('\n══════════════════════════════════════════════════════');
console.log('📊 EEIS SumUp Reconciliation — Full Payment History');
console.log('══════════════════════════════════════════════════════');

function yearOf(d){
  if(d>='2025-09-01')return'2025-26';
  if(d>='2024-09-01')return'2024-25';
  if(d>='2023-09-01')return'2023-24';
  return'older';
}

const noCurrentPmt=[];
[...touched].sort((a,b)=>(db[a]['Surname']||'').localeCompare(db[b]['Surname']||'')).forEach(idx=>{
  const s=db[idx];
  const inst=s.instalments||[];
  const name=`${s['First Name']} ${s['Surname']}`;
  const hasCurrent=inst.some(i=>i.date>=CYS&&i.date<=CYE);
  
  // Group by year
  const byYear={};
  inst.forEach(i=>{
    const yr=yearOf(i.date||'');
    byYear[yr]=byYear[yr]||{total:0,dates:[],methods:[]};
    byYear[yr].total+=parseFloat(i.amount)||0;
    byYear[yr].dates.push(i.date);
    byYear[yr].methods.push(i.method);
  });
  
  const pattern=Object.entries(byYear).sort().map(([yr,info])=>{
    const hasSept=info.dates.some(d=>{const m=d.slice(5,7);return m>='09'&&m<='11';});
    const hasJan =info.dates.some(d=>{const m=d.slice(5,7);return m>='01'&&m<='02';});
    const type=info.total>200?'FULL':'HALF';
    const timing=(hasSept?'✓Sep':'✗Sep')+(hasJan?' ✓Jan':' ✗Jan');
    return `${yr}:£${info.total}(${type}) ${timing}`;
  }).join(' │ ');
  
  const icon=hasCurrent?'✅':'❌';
  console.log(`${icon} ${name} (${s['Class']||'?'}) — ${pattern}`);
  if(!hasCurrent)noCurrentPmt.push({idx,s});
});

console.log('\n──────────────────────────────────────────────────────');
console.log(`⚠️  NO 2025-26 PAYMENT FOUND (${noCurrentPmt.length} students from matched history):`);
noCurrentPmt.forEach(({s})=>{
  const last=(s.instalments||[]).slice(-1)[0];
  console.log(`   ❌ ${s['First Name']} ${s['Surname']} (${s['Class']||'?'}) — last paid: ${last?last.date:'never'}`);
});

if(stats.warnings.length){
  console.log('\n── Assignment Warnings ─────────────────────────────────');
  stats.warnings.forEach(w=>console.log('  ⚡',w));
}

console.log('\n══════════════════════════════════════════════════════');
console.log(`✅ Payments matched:    ${stats.matched}`);
console.log(`➕ New instalments:     ${stats.added}`);
console.log(`⏩ Duplicates skipped:  ${stats.dup}`);
console.log(`❓ Unmatched orders:    ${stats.unmatched} (not in db)`);
console.log(`👥 Students updated:    ${touched.size}`);
console.log('══════════════════════════════════════════════════════');

if(stats.added>0){
  console.log('\n💾 Saving to Google Sheets…');
  saveDb().then(()=>console.log('✅ All done — SumUp payment history saved to Sheets!'))
          .catch(e=>console.error('❌ Save failed:',e.message));
} else {
  console.log('\nℹ️ No new instalments added — nothing to save.');
}
})();
