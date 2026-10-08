<?php

declare(strict_types=1);

namespace ymwl\think8mcp\Skills\Remote;

/**
 * 远程 Skill 安全风险等级
 *
 * 注意：为兼容 PHP 8.0（本项目运行环境为 8.0.x），此处未使用 PHP 8.1 的 enum，
 * 改用「常量 + 值对象」实现，对外语义与枚举一致：
 *   - 常量：Risk::Critical / High / Medium / Low / Safe
 *   - 工厂：Risk::from($value) / Risk::tryFrom($value)
 *   - 实例：->weight() / ->label() / ->color() / ->value()
 */
final class Risk
{
    public const Critical = 'critical';
    public const High     = 'high';
    public const Medium   = 'medium';
    public const Low      = 'low';
    public const Safe     = 'safe';

    private string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * 全部合法风险值
     *
     * @return string[]
     */
    public static function values(): array
    {
        return [self::Critical, self::High, self::Medium, self::Low, self::Safe];
    }

    /**
     * 由字符串构造，非法值抛异常
     */
    public static function from(string $value): self
    {
        if (!in_array($value, self::values(), true)) {
            throw new \InvalidArgumentException("Invalid risk value: {$value}");
        }

        return new self($value);
    }

    /**
     * 由字符串构造，非法值返回 null（等价枚举 tryFrom）
     */
    public static function tryFrom(string $value): ?self
    {
        return in_array($value, self::values(), true) ? new self($value) : null;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function weight(): int
    {
        switch ($this->value) {
            case self::Critical:
                return 5;
            case self::High:
                return 4;
            case self::Medium:
                return 3;
            case self::Low:
                return 2;
            default:
                return 1;
        }
    }

    public function label(): string
    {
        switch ($this->value) {
            case self::Critical:
                return 'Critical Risk';
            case self::High:
                return 'High Risk';
            case self::Medium:
                return 'Med Risk';
            case self::Low:
                return 'Low Risk';
            default:
                return 'Safe';
        }
    }

    public function color(): string
    {
        switch ($this->value) {
            case self::Critical:
            case self::High:
                return 'red';
            case self::Medium:
                return 'yellow';
            default:
                return 'green';
        }
    }
}
