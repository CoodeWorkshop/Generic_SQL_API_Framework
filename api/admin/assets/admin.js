(() => {
  'use strict';
  const apiUrl = '/index.php';
  const content = document.querySelector('#content');
  const notice = document.querySelector('#notice');
  const navigation = document.querySelector('#navigation');
  const logout = document.querySelector('#logout');
  const title = document.querySelector('#page-title');
  let csrfToken = '';

  const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  const row = response => response.data && response.data[0] ? response.data[0] : {};
  const showNotice = (message, error = false) => {
    notice.className = message ? `notice${error ? ' error' : ''}` : '';
    notice.textContent = message;
  };
  async function call(payload, csrf = false) {
    const headers = {'Content-Type': 'application/json'};
    if (csrf && csrfToken) headers['X-CSRF-Token'] = csrfToken;
    const response = await fetch(apiUrl, {method:'POST', credentials:'same-origin', headers, body:JSON.stringify(payload)});
    const nextToken = response.headers.get('X-CSRF-Token');
    if (nextToken) csrfToken = nextToken;
    const body = await response.json().catch(() => ({success:false,message:'The API returned an unreadable response.'}));
    if (!response.ok || !body.success) throw new Error(body.message || 'The operation failed.');
    return body;
  }
  async function loadCsrf() { csrfToken = row(await call({action:'auth.csrf'})).csrfToken || ''; }

  function setupView() {
    title.textContent = 'Create the initial administrator';
    content.innerHTML = `<form id="setup-form" class="stack"><p class="help">Create the first local administrator. Passwords must meet the backend password policy.</p><label>Username<input name="username" autocomplete="username" required maxlength="128"></label><label>Password<input name="password" type="password" autocomplete="new-password" required></label><label>Confirm password<input name="passwordConfirmation" type="password" autocomplete="new-password" required></label><div><button>Create administrator</button></div></form>`;
    document.querySelector('#setup-form').addEventListener('submit', async event => {
      event.preventDefault(); showNotice('');
      const values = new FormData(event.currentTarget);
      try {
        await call({action:'setup.createAdmin',username:values.get('username'),password:values.get('password'),passwordConfirmation:values.get('passwordConfirmation')}, true);
        showNotice('Administrator created. Sign in to continue.'); await loginView();
      } catch (error) { showNotice(error.message, true); }
    });
  }

  async function loginView() {
    title.textContent = 'Administrator sign in';
    navigation.hidden = true; logout.hidden = true;
    content.innerHTML = `<form id="login-form" class="stack"><p class="help">Use an enabled administrator account. The browser session never exposes API keys.</p><label>Username<input name="username" autocomplete="username" required></label><label>Password<input name="password" type="password" autocomplete="current-password" required></label><div><button>Sign in</button></div></form>`;
    document.querySelector('#login-form').addEventListener('submit', async event => {
      event.preventDefault(); showNotice(''); const values = new FormData(event.currentTarget);
      try { await call({action:'auth.login',username:values.get('username'),password:values.get('password')}, true); await enterConsole(); }
      catch (error) { showNotice(error.message, true); }
    });
  }

  const yesNo = value => value ? 'Ready' : 'Not ready';
  async function overview() {
    const status = row(await call({action:'admin.status'}));
    title.textContent = 'Overview';
    content.innerHTML = `<div class="grid">
      <article class="card"><h3>Database</h3><p>${escapeHtml(yesNo(status.database.readable))}</p></article>
      <article class="card"><h3>Encrypted configuration</h3><p>${escapeHtml(yesNo(status.encryption.enabled))}</p></article>
      <article class="card"><h3>Authentication</h3><p>${escapeHtml(status.authentication.mode)}</p></article>
      <article class="card"><h3>CORS origins</h3><p>${escapeHtml(status.cors.originCount)}</p></article>
      <article class="card"><h3>SQL parser</h3><p>${escapeHtml(yesNo(status.sqlParser.available))}</p></article>
      <article class="card"><h3>PHP</h3><p>${escapeHtml(status.system.phpVersion)}</p></article>
    </div>`;
  }

  async function databaseView() {
    const db = row(await call({action:'admin.database.get'})); title.textContent = 'Database';
    const options = db.availableDrivers.map(driver => `<option${driver===db.driver?' selected':''}>${escapeHtml(driver)}</option>`).join('');
    content.innerHTML = `<form id="database-form" class="stack"><p class="help">Credentials are encrypted before they are written. Leave password blank to retain the currently stored password.</p><div class="row"><label>Provider<select name="provider"><option value="sqlserver">SQL Server</option></select></label><label>ODBC driver<select name="driver">${options}</select></label></div><div class="row"><label>Server<input name="server" required value="${escapeHtml(db.server)}"></label><label>Port<input name="port" inputmode="numeric" value="${escapeHtml(db.port)}"></label></div><label>Database<input name="database" required value="${escapeHtml(db.database)}"></label><div class="row"><label>Authentication<select name="authentication"><option value="sql"${db.authentication==='sql'?' selected':''}>SQL login</option><option value="windows"${db.authentication==='windows'?' selected':''}>Windows integrated</option></select></label><label>Username<input name="username" value="${escapeHtml(db.username)}"></label></div><label>Password<input name="password" type="password" autocomplete="new-password" placeholder="${db.passwordConfigured?'Stored password will be retained':'Required for SQL login'}"></label><label class="check"><input name="encrypt" type="checkbox"${db.encrypt?' checked':''}> Encrypt SQL Server traffic</label><label class="check"><input name="trustServerCertificate" type="checkbox"${db.trustServerCertificate?' checked':''}> Trust server certificate</label><div class="actions"><button type="button" id="test-database" class="secondary">Test connection</button><button>Save encrypted configuration</button></div></form>`;
    const form = document.querySelector('#database-form');
    const payload = () => { const v=new FormData(form); return {provider:v.get('provider'),driver:v.get('driver'),server:v.get('server'),port:v.get('port'),database:v.get('database'),authentication:v.get('authentication'),username:v.get('username'),password:v.get('password')||null,encrypt:v.get('encrypt')==='on',trustServerCertificate:v.get('trustServerCertificate')==='on'}; };
    document.querySelector('#test-database').addEventListener('click', async () => { try { await call({action:'admin.database.test',database:payload()},true); showNotice('Connection succeeded.'); } catch(error){showNotice(error.message,true);} });
    form.addEventListener('submit', async event => { event.preventDefault(); try { await call({action:'admin.database.save',database:payload()},true); showNotice('Encrypted database configuration saved.'); await databaseView(); } catch(error){showNotice(error.message,true);} });
  }

  async function corsView() {
    const settings=row(await call({action:'admin.settings.get'})); title.textContent='CORS';
    content.innerHTML=`<form id="cors-form" class="stack"><p class="help">Enter one exact HTTP or HTTPS origin per line. Wildcards and URL paths are rejected.</p><label>Allowed origins<textarea name="origins">${escapeHtml(settings.cors.allowedOrigins.join('\n'))}</textarea></label><label class="check"><input name="credentials" type="checkbox"${settings.cors.credentialsEnabled?' checked':''}> Allow browser credentials</label><fieldset><legend>Allowed methods</legend><label class="check"><input type="checkbox" checked disabled> POST</label><label class="check"><input type="checkbox" checked disabled> OPTIONS</label></fieldset><div><button>Save CORS settings</button></div></form>`;
    document.querySelector('#cors-form').addEventListener('submit',async event=>{event.preventDefault();const v=new FormData(event.currentTarget);const origins=String(v.get('origins')).split(/\r?\n/).map(x=>x.trim()).filter(Boolean);try{await call({action:'admin.cors.save',cors:{allowedOrigins:origins,credentialsEnabled:v.get('credentials')==='on',allowedMethods:['POST','OPTIONS']}},true);showNotice('CORS settings saved.');}catch(error){showNotice(error.message,true);}});
  }

  async function authenticationView() {
    const settings=row(await call({action:'admin.settings.get'})); title.textContent='Authentication';
    content.innerHTML=`<form id="authentication-form" class="stack"><p class="warning">API keys are supplied only through the GENERIC_SQL_API_KEY environment variable. This console never creates, displays, or stores them.</p><label>API authentication mode<select name="mode">${['session','api_key','session+api_key','none'].map(mode=>`<option value="${mode}"${settings.authentication.mode===mode?' selected':''}>${mode}</option>`).join('')}</select></label><p class="help">API key configured: ${settings.authentication.apiKeyConfigured?'yes':'no'}. Administrator endpoints always require an administrator session.</p><div><button>Save authentication mode</button></div></form>`;
    document.querySelector('#authentication-form').addEventListener('submit',async event=>{event.preventDefault();const v=new FormData(event.currentTarget);try{await call({action:'admin.authentication.save',mode:v.get('mode')},true);showNotice('Authentication mode saved.');}catch(error){showNotice(error.message,true);}});
  }

  function parserView() {
    title.textContent='SQL → Universal JSON'; content.innerHTML=`<div class="stack"><p class="help">Conversion uses the existing non-executing SQL parser. No database connection is opened.</p><label>SQL<textarea id="sql-input" class="sql-input" spellcheck="false">SELECT TOP 10 * FROM dbo.Example</textarea></label><div><button id="convert-sql">Convert</button></div><label>Universal JSON<textarea id="sql-output" class="sql-output" readonly spellcheck="false"></textarea></label></div>`;
    document.querySelector('#convert-sql').addEventListener('click',async()=>{try{const result=row(await call({action:'admin.sqlParser.convert',sql:document.querySelector('#sql-input').value}));document.querySelector('#sql-output').value=JSON.stringify(result.result,null,2);showNotice('SQL converted without database execution.');}catch(error){showNotice(error.message,true);}});
  }

  async function detailView(kind) {
    const status=row(await call({action:'admin.status'})); title.textContent=kind==='security'?'Security':'System'; const value=kind==='security'?{encryption:status.encryption,authentication:status.authentication,cors:status.cors,adminAccess:'Loopback only'}:status.system;
    content.innerHTML=`<dl class="detail-list">${Object.entries(value).map(([key,val])=>`<dt>${escapeHtml(key)}</dt><dd><pre>${escapeHtml(typeof val==='object'?JSON.stringify(val,null,2):val)}</pre></dd>`).join('')}</dl>`;
  }

  function currentRoute(){const path=location.pathname.replace(/\/$/,'');return path==='/admin'?'overview':path.split('/').pop();}
  async function render(){showNotice('');const route=currentRoute();document.querySelectorAll('nav a').forEach(link=>link.classList.toggle('active',link.dataset.route===route));try{if(route==='database')await databaseView();else if(route==='cors')await corsView();else if(route==='authentication')await authenticationView();else if(route==='sql-parser')parserView();else if(route==='security'||route==='system')await detailView(route);else await overview();}catch(error){showNotice(error.message,true);}}
  async function enterConsole(){navigation.hidden=false;logout.hidden=false;await render();}
  navigation.addEventListener('click',event=>{const link=event.target.closest('a');if(!link)return;event.preventDefault();history.pushState({},'',link.href);render();});
  addEventListener('popstate',render);
  logout.addEventListener('click',async()=>{try{await call({action:'auth.logout'},true);}finally{csrfToken='';await loadCsrf();await loginView();}});
  (async()=>{try{await loadCsrf();const setup=row(await call({action:'setup.status'}));if(!setup.initialized){setupView();return;}const session=row(await call({action:'auth.session'}));if(session.authenticated&&session.user&&session.user.isAdmin)await enterConsole();else await loginView();}catch(error){title.textContent='Unavailable';content.textContent='The local administration API could not be reached.';showNotice(error.message,true);}})();
})();
