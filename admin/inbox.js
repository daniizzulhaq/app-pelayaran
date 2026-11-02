'use strict';

const inboxTopics = {partnership:'Kebutuhan awak / bisnis', recruitment:'Pelaut / karier', 'crew-management':'Manajemen awak', training:'Pelatihan', payroll:'Penggajian', general:'Pertanyaan umum'};

async function loadEnquiries() {
  const container = document.getElementById('enquiry-list');
  const refresh = document.getElementById('refresh-enquiries');
  const state = document.getElementById('enquiry-state');
  refresh.disabled = true;
  state.textContent = 'Memuat pesan…';
  try {
    const result = await api('/api/admin/enquiries');
    container.replaceChildren();
    state.textContent = result.enquiries.length ? `${result.enquiries.length} pesan. Balas melalui email pengirim.` : 'Belum ada pesan masuk dari website.';
    for (const entry of result.enquiries) {
      const card = document.createElement('article'); card.className = 'enquiry-card';
      const heading = document.createElement('div'); heading.className = 'enquiry-heading';
      const name = document.createElement('strong'); name.textContent = entry.name;
      const time = document.createElement('time'); time.dateTime = entry.createdAt;
      time.textContent = new Date(entry.createdAt).toLocaleString('id-ID', {dateStyle:'medium', timeStyle:'short'});
      heading.append(name, time);
      const topic = document.createElement('small'); topic.textContent = inboxTopics[entry.interest] || entry.interest;
      const email = document.createElement('a'); email.href = `mailto:${entry.email}`; email.textContent = entry.email;
      const message = document.createElement('p'); message.className = 'enquiry-message'; message.textContent = entry.message;
      const actions = document.createElement('div'); actions.className = 'enquiry-actions';
      const reply = document.createElement('a'); reply.className = 'text-link'; reply.textContent = 'Balas melalui email ↗';
      reply.href = `mailto:${entry.email}?subject=${encodeURIComponent('Re: HPO — ' + (inboxTopics[entry.interest] || 'Pertanyaan'))}`;
      const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'enquiry-delete'; remove.textContent = 'Hapus pesan';
      remove.addEventListener('click', async () => {
        if (!confirm('Hapus permanen pesan dari ' + entry.name + '?')) return;
        remove.disabled = true;
        try { await post('/api/admin/enquiries', {deleteId:entry.id}); await loadEnquiries(); }
        catch (error) { toast(error.message, true); remove.disabled = false; }
      });
      actions.append(reply, remove); card.append(heading, topic, email, message, actions); container.append(card);
    }
  } catch (error) {
    state.textContent = error.status === 401 ? 'Sesi berakhir. Silakan masuk kembali.' : 'Pesan belum dapat dimuat. Coba muat ulang.';
    if (error.status === 401) showAuth();
  } finally { refresh.disabled = false; }
}

document.getElementById('refresh-enquiries').addEventListener('click', loadEnquiries);
