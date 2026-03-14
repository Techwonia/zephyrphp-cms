<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

#[ORM\Entity]
#[ORM\Table(name: 'cms_dashboard_layouts')]
#[ORM\HasLifecycleCallbacks]
class DashboardLayout extends Model
{
    #[ORM\Column(name: 'user_id', type: 'integer', unique: true)]
    protected int $userId;

    #[ORM\Column(type: 'json')]
    protected array $layout = [];

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function getLayout(): array
    {
        return $this->layout;
    }

    public function setLayout(array $layout): self
    {
        $this->layout = $layout;
        return $this;
    }
}
