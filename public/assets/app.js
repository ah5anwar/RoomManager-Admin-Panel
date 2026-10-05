// ============================================
// শেয়ার্ড হেলপার ফাংশন
// ============================================
const API_BASE = '../../api'; // public/admin/ বা public/customer/ থেকে RoomManager/api/ এ পৌঁছাতে দুই ধাপ উপরে যেতে হয়

function getToken() { return localStorage.getItem('rm_token'); }
function getUser() { try { return JSON.parse(localStorage.getItem('rm_user')); } catch { return null; } }
function saveSession(token, user) {
  localStorage.setItem('rm_token', token);
  localStorage.setItem('rm_user', JSON.stringify(user));
}
function logout() {
  localStorage.removeItem('rm_token');
  localStorage.removeItem('rm_user');
  window.location.href = '../login.html';
}

async function api(path, { method = 'GET', body = null, isForm = false } = {}) {
  const headers = {};
  const token = getToken();
  if (token) headers['Authorization'] = 'Bearer ' + token;
  if (!isForm && body) headers['Content-Type'] = 'application/json';

  const res = await fetch(`${API_BASE}/${path}`, {
    method,
    headers,
    body: isForm ? body : (body ? JSON.stringify(body) : null),
  });

  if (res.status === 401) { logout(); return; }

  const data = await res.json().catch(() => ({ success: false, message: 'সার্ভার এরর' }));
  return data;
}

function toast(msg) {
  let el = document.getElementById('toast');
  if (!el) {
    el = document.createElement('div');
    el.id = 'toast';
    el.className = 'toast';
    document.body.appendChild(el);
  }
  el.textContent = msg;
  el.classList.add('show');
  clearTimeout(window._toastTimer);
  window._toastTimer = setTimeout(() => el.classList.remove('show'), 2500);
}

function requireRole(roleOrRoles) {
  const user = getUser();
  if (!getToken() || !user) { window.location.href = '../login.html'; return null; }
  const allowed = Array.isArray(roleOrRoles) ? roleOrRoles : (roleOrRoles ? [roleOrRoles] : null);
  if (allowed && !allowed.includes(user.role)) {
    window.location.href = (user.role === 'admin' || user.role === 'manager') ? '../admin/dashboard.html' : '../customer/dashboard.html';
    return null;
  }
  return user;
}

function fmtMoney(n) { return (Number(n) || 0).toLocaleString('bn-BD'); }
function pad2(n) { return String(n).padStart(2, '0'); }
function daysInMonth(y, m) { return new Date(y, m, 0).getDate(); }
const BN_MONTHS = ['', 'জানুয়ারি','ফেব্রুয়ারি','মার্চ','এপ্রিল','মে','জুন','জুলাই','আগস্ট','সেপ্টেম্বর','অক্টোবর','নভেম্বর','ডিসেম্বর'];
const BN_WEEKDAYS = ['রবি','সোম','মঙ্গল','বুধ','বৃহঃ','শুক্র','শনি'];
const DUTY_TYPES = ['market', 'cooking', 'trash', 'cleaning'];
const DUTY_LABELS = { market: 'বাজার করা', cooking: 'রান্না করা', trash: 'ময়লা ফেলা', cleaning: 'রুম ক্লিন করা' };
const DUTY_ICONS = { market: '🛒', cooking: '🍳', trash: '🗑️', cleaning: '🧹' };
const STATUS_LABELS = { paid: 'পরিশোধিত', due: 'বকেয়া', partial: 'আংশিক', pending: 'বাকি আছে', done: 'সম্পন্ন', missed: 'মিস হয়েছে' };
const NOTIFY_STATUS_LABELS = { queued: 'সারিতে আছে', sent: 'পাঠানো হয়েছে', delivered: '✓✓ পৌঁছেছে', read: '✓✓ পড়েছে', failed: 'ব্যর্থ' };

function currentMonth() { return new Date().getMonth() + 1; }
function currentYear() { return new Date().getFullYear(); }

/** (year, month) থেকে delta মাস যোগ/বিয়োগ করে নতুন {year, month} রিটার্ন করে */
function addMonths(year, month, delta) {
  const total = year * 12 + (month - 1) + delta;
  const newYear = Math.floor(total / 12);
  const newMonth = ((total % 12) + 12) % 12 + 1;
  return { year: newYear, month: newMonth };
}

/**
 * মাস/সাল ড্রপডাউনের সীমা তৈরি করে — maxAheadMonths = বর্তমান মাস থেকে সর্বোচ্চ কত মাস
 * আগামি পর্যন্ত দেখানো যাবে (0 = শুধু বর্তমান মাস পর্যন্ত, ভবিষ্যতে না)।
 * minYear/minMonth দিলে সিস্টেম শুরুর তারিখের আগে কিছু দেখানো হবে না (না দিলে ডিফল্ট pastYears বছর পিছনে)।
 */
function monthYearBounds(maxAheadMonths, pastYears = 3, minYear = null, minMonth = null) {
  const { year: maxYear, month: maxMonth } = addMonths(currentYear(), currentMonth(), maxAheadMonths);
  const effMinYear = minYear != null ? minYear : currentYear() - pastYears;
  const effMinMonth = minYear != null && minMonth != null ? minMonth : 1;
  return {
    maxYear, maxMonth, minYear: effMinYear, minMonth: effMinMonth,
    yearOptions() { const arr = []; for (let y = this.minYear; y <= this.maxYear; y++) arr.push(y); return arr; },
    monthOptionsFor(year) {
      const hi = year === this.maxYear ? this.maxMonth : 12;
      const lo = year === this.minYear ? this.minMonth : 1;
      const arr = []; for (let m = lo; m <= hi; m++) arr.push(m); return arr;
    },
    clamp(month, year) {
      if (year > this.maxYear || (year === this.maxYear && month > this.maxMonth)) return { month: this.maxMonth, year: this.maxYear };
      if (year < this.minYear || (year === this.minYear && month < this.minMonth)) return { month: this.minMonth, year: this.minYear };
      return { month, year };
    },
  };
}

/** সেটিংস থেকে সিস্টেম শুরুর মাস/বছর আনে (না থাকলে null) - সব ইউজার কল করতে পারবে */
async function getSystemStartDate() {
  const res = await api('settings.php?action=public');
  if (res && res.success && res.data) {
    const y = Number(res.data.system_start_year), m = Number(res.data.system_start_month);
    if (y && m) return { year: y, month: m };
  }
  return null;
}

/** সার্ভার থেকে সর্বশেষ ইউজার তথ্য (বিশেষত পারমিশন) এনে লোকাল সেশন আপডেট করে */
async function refreshMe() {
  const res = await api('auth.php?action=me');
  if (res && res.success && res.data) {
    localStorage.setItem('rm_user', JSON.stringify(res.data));
    return res.data;
  }
  return null;
}
