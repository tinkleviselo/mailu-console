<?php
/**
 * Deployment centre: generates the Linux installation script for a Mailu
 * instance and (optionally) pushes it to the VPS over SSH.
 */

namespace App\Controller;

use App\Core\Config;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Service\AuditService;
use App\Service\DeployScriptService;
use App\Service\RemoteDeploy;
use App\Service\ServerService;

final class DeployController extends Controller
{
    public function index()
    {
        $this->render('deploy/index', array(
            'title' => '部署中心',
            'scripts' => DeployScriptService::all(40),
            'jobs' => RemoteDeploy::jobs(20),
            'servers' => ServerService::all(),
            'bundleOk' => DeployScriptService::bundleAvailable(),
            'bundleSize' => DeployScriptService::bundleAvailable() ? strlen(DeployScriptService::bundleContent()) : 0,
            'remote' => RemoteDeploy::status(),
            'defaults' => DeployScriptService::defaultParams(null),
            'mailuVersion' => (string) Config::get('deploy.mailu_version', '2024.06.61'),
            'generated' => null,
        ));
    }

    public function generate()
    {
        $serverId = Request::int('server_id', 0);
        $server = $serverId > 0 ? ServerService::find($serverId) : null;
        try {
            $params = DeployScriptService::fromInput($this->input(), $server);
            $result = DeployScriptService::generate($params, array(
                'author' => $this->adminName(),
                'server_id' => $serverId,
                'name' => Request::str('script_name', '部署脚本 ' . $params['MAILU_DOMAIN']),
            ));
            Flash::success('部署脚本已生成：' . $result['filename'] . '（含 API 令牌，请妥善保存）');
            $this->render('deploy/index', array(
                'title' => '部署中心',
                'scripts' => DeployScriptService::all(40),
                'jobs' => RemoteDeploy::jobs(20),
                'servers' => ServerService::all(),
                'bundleOk' => DeployScriptService::bundleAvailable(),
                'bundleSize' => DeployScriptService::bundleAvailable() ? strlen(DeployScriptService::bundleContent()) : 0,
                'remote' => RemoteDeploy::status(),
                'defaults' => $params,
                'mailuVersion' => (string) Config::get('deploy.mailu_version', '2024.06.61'),
                'generated' => $result,
            ));
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), '/deploy');
        }
    }

    public function download($params)
    {
        $script = DeployScriptService::find((int) $params['id']);
        if (!$script) {
            Response::abort(404, '部署脚本不存在');
        }
        AuditService::log('deploy.download', 'ok', array('filename' => $script['filename']), array('target_type' => 'deploy', 'target_label' => $script['filename']));
        Response::download($script['filename'], $script['content'], 'application/x-sh; charset=utf-8');
    }

    public function destroy($params)
    {
        try {
            DeployScriptService::delete((int) $params['id']);
            Flash::success('脚本已删除');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }
        $this->redirect('/deploy');
    }

    public function run($params)
    {
        try {
            $result = RemoteDeploy::run((int) $params['id'], array(
                'host' => Request::str('target_host'),
                'port' => Request::int('target_port', 22),
                'user' => Request::str('target_user', 'root'),
                'sudo' => Request::bool('use_sudo', true),
            ));
            if ($result['exit_code'] === 0) {
                Flash::success('远程部署脚本执行完成（exit=0）');
            } else {
                Flash::error('远程执行结束但退出码为 ' . $result['exit_code'] . '，请查看执行日志');
            }
            $this->redirect('/deploy/jobs/' . $result['job_id']);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), '/deploy');
        }
    }

    public function bundle()
    {
        $content = DeployScriptService::bundleContent();
        if ($content === null) {
            Response::abort(404, '部署脚本模板缺失');
        }
        Response::download('mailu-deploy-bundle.sh', $content, 'application/x-sh; charset=utf-8');
    }

    public function package()
    {
        $zip = DeployScriptService::packageZip();
        if ($zip === null) {
            $content = DeployScriptService::bundleContent();
            if ($content === null) {
                Response::abort(404, '部署包不可用：缺少 resources/mailu-deploy.bundle.sh');
            }
            Response::download('mailu-deploy-bundle.sh', $content, 'application/x-sh; charset=utf-8');
        }
        AuditService::log('deploy.package_download', 'ok', array('file' => basename($zip)));
        Response::download('mailu-deploy-package.zip', (string) file_get_contents($zip), 'application/zip');
    }

    public function job($params)
    {
        $job = RemoteDeploy::findJob((int) $params['id']);
        if (!$job) {
            Response::abort(404, '任务不存在');
        }
        $this->render('deploy/job', array(
            'title' => '部署任务 #' . $job['id'],
            'job' => $job,
            'log' => RemoteDeploy::jobLog($job),
        ));
    }
}