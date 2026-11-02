<?php
/**
 * Route table. Middleware names: installed, not_installed, auth, guest, csrf, api.
 * @var App\Core\Router $router
 */

use App\Controller\AliasController;
use App\Controller\ApiController;
use App\Controller\AuditController;
use App\Controller\AuthController;
use App\Controller\DashboardController;
use App\Controller\DeployController;
use App\Controller\DomainController;
use App\Controller\ServerController;
use App\Controller\SettingController;
use App\Controller\SetupController;
use App\Controller\ToolController;
use App\Controller\UserController;

// --- authentication ---------------------------------------------------------
$router->get('/login', array(AuthController::class, 'showLogin'), array('installed', 'guest'))->name('login');
$router->post('/login', array(AuthController::class, 'login'), array('installed', 'guest', 'csrf'));
$router->post('/logout', array(AuthController::class, 'logout'), array('auth', 'csrf'))->name('logout');

// --- first run setup --------------------------------------------------------
$router->get('/setup', array(SetupController::class, 'form'), array('not_installed'))->name('setup');
$router->post('/setup', array(SetupController::class, 'install'), array('not_installed', 'csrf'));
$router->get('/setup/requirements', array(SetupController::class, 'requirements'), array('not_installed'));

// --- dashboard --------------------------------------------------------------
$router->get('/', array(DashboardController::class, 'index'), array('installed', 'auth'))->name('dashboard');

// --- mail servers (Mailu instances) ----------------------------------------
$router->get('/servers', array(ServerController::class, 'index'), array('installed', 'auth'))->name('servers.index');
$router->get('/servers/create', array(ServerController::class, 'create'), array('installed', 'auth'))->name('servers.create');
$router->post('/servers', array(ServerController::class, 'store'), array('installed', 'auth', 'csrf'));
$router->get('/servers/{id}', array(ServerController::class, 'show'), array('installed', 'auth'))->name('servers.show');
$router->get('/servers/{id}/edit', array(ServerController::class, 'edit'), array('installed', 'auth'))->name('servers.edit');
$router->post('/servers/{id}', array(ServerController::class, 'update'), array('installed', 'auth', 'csrf'));
$router->post('/servers/{id}/delete', array(ServerController::class, 'destroy'), array('installed', 'auth', 'csrf'));
$router->post('/servers/{id}/check', array(ServerController::class, 'check'), array('installed', 'auth', 'csrf'))->name('servers.check');
$router->post('/servers/{id}/sync', array(ServerController::class, 'sync'), array('installed', 'auth', 'csrf'))->name('servers.sync');
$router->post('/servers/sync-all', array(ServerController::class, 'syncAll'), array('installed', 'auth', 'csrf'))->name('servers.syncAll');

// --- domains ----------------------------------------------------------------
$router->get('/domains', array(DomainController::class, 'index'), array('installed', 'auth'))->name('domains.index');
$router->get('/domains/create', array(DomainController::class, 'create'), array('installed', 'auth'))->name('domains.create');
$router->post('/domains', array(DomainController::class, 'store'), array('installed', 'auth', 'csrf'));
$router->get('/domains/{id}', array(DomainController::class, 'show'), array('installed', 'auth'))->name('domains.show');
$router->post('/domains/{id}', array(DomainController::class, 'update'), array('installed', 'auth', 'csrf'));
$router->post('/domains/{id}/delete', array(DomainController::class, 'destroy'), array('installed', 'auth', 'csrf'));
$router->post('/domains/{id}/sync', array(DomainController::class, 'sync'), array('installed', 'auth', 'csrf'));
$router->post('/domains/{id}/dkim', array(DomainController::class, 'generateDkim'), array('installed', 'auth', 'csrf'));
$router->post('/domains/{id}/dns-check', array(DomainController::class, 'dnsCheck'), array('installed', 'auth', 'csrf'));

// --- users ------------------------------------------------------------------
$router->get('/users', array(UserController::class, 'index'), array('installed', 'auth'))->name('users.index');
$router->get('/users/create', array(UserController::class, 'create'), array('installed', 'auth'))->name('users.create');
$router->post('/users', array(UserController::class, 'store'), array('installed', 'auth', 'csrf'));
$router->get('/users/bulk', array(UserController::class, 'bulk'), array('installed', 'auth'))->name('users.bulk');
$router->post('/users/bulk', array(UserController::class, 'bulkStore'), array('installed', 'auth', 'csrf'));
$router->get('/users/export', array(UserController::class, 'export'), array('installed', 'auth'))->name('users.export');
$router->get('/users/{id}', array(UserController::class, 'show'), array('installed', 'auth'))->name('users.show');
$router->post('/users/{id}', array(UserController::class, 'update'), array('installed', 'auth', 'csrf'));
$router->post('/users/{id}/delete', array(UserController::class, 'destroy'), array('installed', 'auth', 'csrf'));
$router->post('/users/{id}/toggle', array(UserController::class, 'toggle'), array('installed', 'auth', 'csrf'));
$router->post('/users/{id}/password', array(UserController::class, 'resetPassword'), array('installed', 'auth', 'csrf'));
$router->post('/users/{id}/sync', array(UserController::class, 'sync'), array('installed', 'auth', 'csrf'));
$router->get('/users/{id}/credentials', array(UserController::class, 'credentials'), array('installed', 'auth'))->name('users.credentials');

// --- aliases ----------------------------------------------------------------
$router->get('/aliases', array(AliasController::class, 'index'), array('installed', 'auth'))->name('aliases.index');
$router->get('/aliases/create', array(AliasController::class, 'create'), array('installed', 'auth'))->name('aliases.create');
$router->post('/aliases', array(AliasController::class, 'store'), array('installed', 'auth', 'csrf'));
$router->post('/aliases/{id}', array(AliasController::class, 'update'), array('installed', 'auth', 'csrf'));
$router->post('/aliases/{id}/delete', array(AliasController::class, 'destroy'), array('installed', 'auth', 'csrf'));
$router->post('/aliases/sync', array(AliasController::class, 'sync'), array('installed', 'auth', 'csrf'));

// --- tools: dns / connectivity probe / credentials / import ------------------
$router->get('/tools/dns', array(ToolController::class, 'dns'), array('installed', 'auth'))->name('tools.dns');
$router->post('/tools/dns/check', array(ToolController::class, 'dnsCheck'), array('installed', 'auth', 'csrf'));
$router->get('/tools/probe', array(ToolController::class, 'probe'), array('installed', 'auth'))->name('tools.probe');
$router->post('/tools/probe', array(ToolController::class, 'probeRun'), array('installed', 'auth', 'csrf'));
$router->get('/tools/credentials', array(ToolController::class, 'credentials'), array('installed', 'auth'))->name('tools.credentials');
$router->post('/tools/credentials/export', array(ToolController::class, 'credentialsExport'), array('installed', 'auth', 'csrf'));
$router->get('/tools/import', array(ToolController::class, 'import'), array('installed', 'auth'))->name('tools.import');
$router->post('/tools/import', array(ToolController::class, 'importRun'), array('installed', 'auth', 'csrf'));

// --- deployment scripts -----------------------------------------------------
$router->get('/deploy', array(DeployController::class, 'index'), array('installed', 'auth'))->name('deploy.index');
$router->post('/deploy/generate', array(DeployController::class, 'generate'), array('installed', 'auth', 'csrf'));
$router->get('/deploy/scripts/{id}/download', array(DeployController::class, 'download'), array('installed', 'auth'))->name('deploy.download');
$router->get('/deploy/scripts/{id}/delete', array(DeployController::class, 'destroy'), array('installed', 'auth'));
$router->post('/deploy/scripts/{id}/run', array(DeployController::class, 'run'), array('installed', 'auth', 'csrf'));
$router->get('/deploy/bundle', array(DeployController::class, 'bundle'), array('installed', 'auth'))->name('deploy.bundle');
$router->get('/deploy/package', array(DeployController::class, 'package'), array('installed', 'auth'))->name('deploy.package');
$router->get('/deploy/jobs/{id}', array(DeployController::class, 'job'), array('installed', 'auth'))->name('deploy.job');

// --- audit + settings -------------------------------------------------------
$router->get('/audit', array(AuditController::class, 'index'), array('installed', 'auth'))->name('audit.index');
$router->get('/settings', array(SettingController::class, 'index'), array('installed', 'auth'))->name('settings.index');
$router->post('/settings', array(SettingController::class, 'save'), array('installed', 'auth', 'csrf'));
$router->post('/settings/password', array(SettingController::class, 'password'), array('installed', 'auth', 'csrf'));
$router->post('/settings/api-token', array(SettingController::class, 'rotateToken'), array('installed', 'auth', 'csrf'));

// --- console JSON API (bearer token or logged in session) -------------------
$router->get('/api/v1/ping', array(ApiController::class, 'ping'), array('installed', 'api'));
$router->get('/api/v1/stats', array(ApiController::class, 'stats'), array('installed', 'api'));
$router->get('/api/v1/servers', array(ApiController::class, 'servers'), array('installed', 'api'));
$router->get('/api/v1/domains', array(ApiController::class, 'domains'), array('installed', 'api'));
$router->get('/api/v1/users', array(ApiController::class, 'users'), array('installed', 'api'));
$router->get('/api/v1/aliases', array(ApiController::class, 'aliases'), array('installed', 'api'));
$router->post('/api/v1/users/create', array(ApiController::class, 'createUser'), array('installed', 'api'));
$router->post('/api/v1/users/update', array(ApiController::class, 'updateUser'), array('installed', 'api'));
$router->post('/api/v1/users/password', array(ApiController::class, 'setPassword'), array('installed', 'api'));
$router->post('/api/v1/users/delete', array(ApiController::class, 'deleteUser'), array('installed', 'api'));
$router->post('/api/v1/domains/create', array(ApiController::class, 'createDomain'), array('installed', 'api'));
$router->post('/api/v1/domains/delete', array(ApiController::class, 'deleteDomain'), array('installed', 'api'));
$router->post('/api/v1/sync', array(ApiController::class, 'sync'), array('installed', 'api'));
$router->get('/api/v1/credentials', array(ApiController::class, 'credentials'), array('installed', 'api'));