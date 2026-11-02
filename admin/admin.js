'use strict';

const $=selector=>document.querySelector(selector);
const authScreen=$('#auth-screen');
const dashboard=$('#dashboard');
const siteForm=$('#site-form');
const saveButton=$('#save-button');
let site=null;
let dirty=false;
let saving=false;
let pendingUploads=0;
let toastTimer;

async function api(url,options={}){
  const endpoint=new URL('../api/index.php',document.baseURI);
  endpoint.searchParams.set('route',url.replace(/^\/api\//,''));
  const response=await fetch(endpoint,{credentials:'same-origin',cache:'no-store',...options});
  let data;try{data=await response.json();}catch{throw new Error('PHP belum berjalan. Buka website melalui hosting PHP atau jalankan start-website.cmd.');}
  if(!response.ok){const error=new Error(data.error||'Permintaan tidak berhasil.');error.status=response.status;throw error;}
  return data;
}
function post(url,data){return api(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});}
function toast(message,error=false){clearTimeout(toastTimer);const element=$('#toast');element.textContent=message;element.classList.toggle('toast-error',error);element.hidden=false;toastTimer=setTimeout(()=>element.hidden=true,5500);}
function displayError(id,message){const element=$(id);element.textContent=message;element.hidden=!message;}
function updateSaveState(){
  saveButton.disabled=!dirty||saving||pendingUploads>0;
  saveButton.textContent=saving?'Menyimpan…':pendingUploads?'Mengunggah gambar…':'Simpan perubahan ↗';
  const indicator=$('#save-indicator');indicator.textContent=dirty?'Ada perubahan belum disimpan':'Semua perubahan tersimpan';indicator.classList.toggle('dirty',dirty);
}
function markDirty(){dirty=true;updateSaveState();}
function savedTime(){const date=site.updatedAt?new Date(site.updatedAt).toLocaleString('id-ID',{dateStyle:'medium',timeStyle:'short'}):null;$('#last-saved').textContent=date?`Terakhir disimpan: ${date}`:'Belum ada perubahan tersimpan.';}
function showAuth(setupRequired=false){
  dashboard.hidden=true;authScreen.hidden=false;
  $('#login-form').hidden=setupRequired;$('#setup-form').hidden=!setupRequired;
  $('#auth-title').textContent=setupRequired?'Siapkan akun admin.':'Selamat datang kembali.';
  $('#auth-description').textContent=setupRequired?'Langkah pertama untuk mengelola website Anda.':'Masuk untuk mengelola website perusahaan.';
}
async function showDashboard(username){
  site=await api('/api/site');dirty=false;
  for(const [key,value]of Object.entries(site.contact)){const field=siteForm.elements.namedItem(key);if(field)field.value=value;}
  $('#admin-username').textContent=username;
  $('.user-avatar').textContent=username[0].toUpperCase();
  renderMedia();renderGallery();savedTime();updateSaveState();
  authScreen.hidden=true;dashboard.hidden=false;
  document.title='Kelola website | Henota Prime Ocean';
  displayError('#dashboard-error','');
  loadEnquiries();
}

for(const [selector,url,errorId]of [['#login-form','/api/admin/login','#login-error'],['#setup-form','/api/admin/setup','#setup-error']]){
  $(selector).addEventListener('submit',async event=>{
    event.preventDefault();const form=event.currentTarget;if(!form.reportValidity())return;
    const data=Object.fromEntries(new FormData(form));
    if(data.confirmPassword&&data.password!==data.confirmPassword){displayError(errorId,'Ulangi password dengan nilai yang sama.');return;}
    displayError(errorId,'');const button=form.querySelector('[type=submit]');button.disabled=true;
    try{const result=await post(url,data);await showDashboard(result.username);form.reset();}
    catch(error){displayError(errorId,error.message);}finally{button.disabled=false;}
  });
}
document.querySelectorAll('.show-password').forEach(button=>button.addEventListener('click',()=>{const input=button.previousElementSibling;const reveal=input.type==='password';input.type=reveal?'text':'password';button.textContent=reveal?'Tutup':'Lihat';button.setAttribute('aria-label',reveal?'Sembunyikan password':'Tampilkan password');}));

const mediaLabels={
  logo:['Logo perusahaan','Header & footer'],hero:['Foto utama / hero','Bagian paling atas website'],about:['Tentang perusahaan','Foto utama profil perusahaan'],aboutSmall:['Foto pendamping','Foto kecil pada profil perusahaan'],careers:['Foto untuk pelaut','Bagian informasi karier'],banner:['Banner samudra','Banner sebelum bagian kontak'],serviceRecruitment:['Layanan rekrutmen','Foto layanan pertama'],serviceInternational:['Manajemen awak kapal','Foto layanan kedua'],serviceOpportunities:['Pelatihan & pengembangan','Foto layanan ketiga']
};
function publicAsset(src){return new URL('../'+src,document.baseURI).href;}
function imageElement(src,alt){const image=document.createElement('img');image.src=publicAsset(src);image.alt=alt;image.loading='lazy';return image;}
function fileInput(){const input=document.createElement('input');input.type='file';input.accept='image/jpeg,image/png,image/webp';input.hidden=true;return input;}
async function upload(file){
  if(!file)throw new Error('Pilih gambar terlebih dahulu.');
  if(file.size>8*1024*1024)throw new Error('Ukuran gambar maksimal 8 MB.');
  if(!['image/jpeg','image/png','image/webp'].includes(file.type))throw new Error('Pilih file JPG, PNG, atau WebP.');
  pendingUploads++;updateSaveState();
  try{const body=new FormData();body.append('image',file);return await api('/api/admin/upload',{method:'POST',body});}
  finally{pendingUploads--;updateSaveState();}
}
function renderMedia(){
  const editor=$('#media-editor');editor.replaceChildren();
  Object.entries(mediaLabels).forEach(([key,[title,description]])=>{
    const card=document.createElement('div');card.className='media-card';card.dataset.key=key;
    const preview=document.createElement('div');preview.className='media-preview';preview.append(imageElement(site.media[key],title));
    const caption=document.createElement('div');caption.className='media-caption';
    const heading=document.createElement('strong');heading.textContent=title;const help=document.createElement('small');help.textContent=description;
    const button=document.createElement('button');button.type='button';button.className='upload-button';button.innerHTML='Ganti gambar <span>↥</span>';
    const input=fileInput();input.setAttribute('aria-label',`Ganti ${title.toLowerCase()}`);button.addEventListener('click',()=>input.click());
    input.addEventListener('change',async()=>{
      if(!input.files[0])return;button.disabled=true;button.textContent='Mengunggah…';
      try{const result=await upload(input.files[0]);site.media[key]=result.src;preview.querySelector('img').src=publicAsset(result.src);markDirty();toast('Gambar siap. Simpan perubahan untuk menampilkannya di website.');}
      catch(error){toast(error.message,true);}finally{button.disabled=false;button.innerHTML='Ganti gambar <span>↥</span>';input.value='';}
    });
    caption.append(heading,help,button,input);card.append(preview,caption);editor.append(card);
  });
}
function editorInput(labelText,value,onInput,max=120){
  const label=document.createElement('label');label.append(document.createTextNode(labelText));
  const input=document.createElement('input');input.type='text';input.className='form-control';input.value=value;input.required=true;input.maxLength=max;
  input.addEventListener('input',()=>{onInput(input.value);markDirty();});label.append(input);return label;
}
function toolButton(text,label,className=''){const button=document.createElement('button');button.type='button';button.textContent=text;button.className=className;button.setAttribute('aria-label',label);return button;}
async function confirmDelete(){
  const dialog=$('#confirm-dialog');dialog.returnValue='cancel';dialog.showModal();
  return await new Promise(resolve=>dialog.addEventListener('close',()=>resolve(dialog.returnValue==='delete'),{once:true}));
}
function renderGallery(){
  const editor=$('#gallery-editor');editor.replaceChildren();$('#gallery-badge').textContent=String(site.gallery.length);
  $('#add-photo-button').disabled=site.gallery.length>=40;
  site.gallery.forEach((photo,index)=>{
    const card=document.createElement('article');card.className='gallery-edit-card';card.dataset.index=String(index);
    const preview=document.createElement('div');preview.className='gallery-edit-photo';preview.append(imageElement(photo.src,photo.id));
    const number=document.createElement('span');number.className='photo-index';number.textContent=String(index+1).padStart(2,'0');preview.append(number);
    const tools=document.createElement('div');tools.className='photo-tools';
    const replace=toolButton('Ganti','Ganti foto','replace-photo');const up=toolButton('↑','Pindahkan foto ke atas');const down=toolButton('↓','Pindahkan foto ke bawah');const remove=toolButton('×','Hapus foto','delete-photo');
    up.disabled=index===0;down.disabled=index===site.gallery.length-1;
    up.addEventListener('click',()=>{[site.gallery[index-1],site.gallery[index]]=[site.gallery[index],site.gallery[index-1]];markDirty();renderGallery();});
    down.addEventListener('click',()=>{[site.gallery[index+1],site.gallery[index]]=[site.gallery[index],site.gallery[index+1]];markDirty();renderGallery();});
    remove.addEventListener('click',async()=>{if(site.gallery.length<=1){toast('Sisakan setidaknya satu foto di galeri.',true);return;}if(await confirmDelete()){site.gallery.splice(index,1);markDirty();renderGallery();}});
    const input=fileInput();input.setAttribute('aria-label',`Ganti foto galeri ${index+1}`);replace.addEventListener('click',()=>input.click());
    input.addEventListener('change',async()=>{
      if(!input.files[0])return;replace.disabled=true;replace.textContent='…';
      try{const result=await upload(input.files[0]);photo.src=result.src;preview.querySelector('img').src=publicAsset(result.src);markDirty();toast('Foto berhasil diganti. Simpan perubahan untuk menerapkannya.');}
      catch(error){toast(error.message,true);}finally{replace.disabled=false;replace.textContent='Ganti';input.value='';}
    });
    tools.append(replace,up,down,remove);preview.append(tools,input);
    const fields=document.createElement('div');fields.className='gallery-edit-fields';
    fields.append(editorInput('Judul · Bahasa Indonesia',photo.id,value=>photo.id=value),editorInput('Title · English',photo.en,value=>photo.en=value));
    const categories=document.createElement('div');categories.className='admin-grid two-columns';categories.append(editorInput('Kategori · ID',photo.categoryId,value=>photo.categoryId=value,60),editorInput('Category · EN',photo.categoryEn,value=>photo.categoryEn=value,60));
    fields.append(categories);card.append(preview,fields);editor.append(card);
  });
}
$('#add-photo-button').addEventListener('click',()=>$('#add-photo-input').click());
$('#add-photo-input').addEventListener('change',async event=>{
  const file=event.target.files[0];if(!file)return;const button=$('#add-photo-button');button.disabled=true;
  try{
    if(site.gallery.length>=40)throw new Error('Galeri maksimal berisi 40 foto.');
    const result=await upload(file);
    site.gallery.push({src:result.src,id:'Foto baru',en:'New photograph',categoryId:'GALERI HPO',categoryEn:'HPO GALLERY'});
    markDirty();renderGallery();toast('Foto ditambahkan. Isi judulnya, lalu simpan perubahan.');
  }catch(error){toast(error.message,true);}finally{button.disabled=site.gallery.length>=40;event.target.value='';}
});

siteForm.addEventListener('input',event=>{if(Object.hasOwn(site.contact,event.target.name)){site.contact[event.target.name]=event.target.value;markDirty();}});
siteForm.addEventListener('submit',async event=>{
  event.preventDefault();if(saving||pendingUploads||!siteForm.reportValidity())return;
  saving=true;siteForm.inert=true;updateSaveState();displayError('#dashboard-error','');
  try{
    const saved=await api('/api/admin/site',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(site)});
    site=saved;dirty=false;
    for(const [key,value]of Object.entries(site.contact)){const field=siteForm.elements.namedItem(key);if(field)field.value=value;}
    renderMedia();renderGallery();savedTime();toast('Perubahan tersimpan. Website sudah diperbarui.');
  }catch(error){displayError('#dashboard-error',error.message);toast(error.message,true);if(error.status===401)showAuth();}
  finally{saving=false;siteForm.inert=false;updateSaveState();}
});

$('#password-form').addEventListener('submit',async event=>{
  event.preventDefault();const form=event.currentTarget;if(!form.reportValidity())return;const data=Object.fromEntries(new FormData(form));
  if(data.newPassword!==data.confirmPassword){displayError('#password-error','Ulangi password baru dengan nilai yang sama.');return;}
  displayError('#password-error','');const button=form.querySelector('button');button.disabled=true;
  try{await post('/api/admin/password',data);form.reset();toast('Password berhasil diperbarui.');}
  catch(error){displayError('#password-error',error.message);if(error.status===401)showAuth();}finally{button.disabled=false;}
});
$('#logout-button').addEventListener('click',async()=>{
  if(dirty&&!confirm('Ada perubahan yang belum disimpan. Tetap keluar?'))return;
  try{await post('/api/admin/logout',{});dirty=false;showAuth();toast('Anda sudah keluar dari akun.');}
  catch(error){toast(error.message,true);}
});
window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
document.querySelectorAll('.admin-nav>a').forEach(link=>link.addEventListener('click',()=>{document.querySelectorAll('.admin-nav>a').forEach(item=>item.classList.toggle('selected',item===link));}));

(async()=>{
  try{const session=await api('/api/admin/session');if(session.authenticated)await showDashboard(session.username);else showAuth(session.setupRequired);}
  catch(error){$('#auth-title').textContent='Aktifkan PHP di hosting.';$('#auth-description').textContent='Panel admin membutuhkan hosting PHP untuk menyimpan perubahan.';$('#connection-notice').hidden=false;$('#login-form').hidden=true;$('#setup-form').hidden=true;}
})();
