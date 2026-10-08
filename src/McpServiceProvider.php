<?php

declare(strict_types=1);

namespace ymwl\think8mcp;

use think\Service;
use ymwl\think8mcp\Commands\AddSkillCommand;
use ymwl\think8mcp\Commands\InstallCommand;
use ymwl\think8mcp\Commands\ListSkillsCommand;
use ymwl\think8mcp\Commands\ServeCommand;
use ymwl\think8mcp\Commands\UpdateCommand;

/**
 * Think8 MCP 服务提供者
 *
 * 通过 composer.json 的 extra.think.services 自动注册，无需手动配置。
 * 仅注册 console 命令，不注册任何 HTTP 路由或中间件，
 * 因此对线上 web 请求零影响。
 */
class McpServiceProvider extends Service
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->commands([
            'mcp:install'     => InstallCommand::class,
            'mcp:serve'       => ServeCommand::class,
            'mcp:update'      => UpdateCommand::class,
            'mcp:add-skill'   => AddSkillCommand::class,
            'mcp:list-skills' => ListSkillsCommand::class,
        ]);
    }
}
