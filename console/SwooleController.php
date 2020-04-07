<?php
/**
 * Created by PhpStorm.
 * Author:TinyMeng
 * Description:
 */

namespace tinymeng\swoole\console;

use tinymeng\swoole\src\SwooleService;
use Illuminate\Console\Command;

class SwooleController extends Command
{

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'swoole {action}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run swoole';

    private $settings = [];
    private $app = null;


    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->prepareSettings();
    }

    /**
     * 初始化配置信息
     * @return [type] [description]
     */
    protected function prepareSettings()
    {
        $this->settings = [];
        try {
            $settings = config('params.swoole');
            var_dump($settings);die;
        }catch (ErrorException $e) {
            throw new ErrorException('Empty param swooleAsync in params. ',8);
        }
        $this->settings = array_replace_recursive(
            $this->settings,
            $settings
        );
    }

    /**
     * 启动swoole
     * Execute the console command.
     * @return mixed
     */
    public function handle()
    {
        $action = $this->argument("action");
        $swooleService = new SwooleService($this->settings,Yii::$app);
        switch ($action) {
            case 'start':
                $swooleService->serviceStart();
                break;
            case 'restart':
                $swooleService->serviceStop();
                $swooleService->serviceStart();
                break;
            case 'stop':
                $swooleService->serviceStop();
                break;
            case 'status':
                $swooleService->serviceStats();
                break;
            case 'list':
                $swooleService->serviceList();
                break;
            default:
                exit('error:参数错误');
                break;
        }
    }
   
}