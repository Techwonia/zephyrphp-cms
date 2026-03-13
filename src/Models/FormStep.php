<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

#[ORM\Entity]
#[ORM\Table(name: 'fb_form_steps')]
#[ORM\HasLifecycleCallbacks]
class FormStep extends Model
{
    #[ORM\ManyToOne(targetEntity: Form::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(name: 'form_id', nullable: false, onDelete: 'CASCADE')]
    protected Form $form;

    #[ORM\Column(name: 'step_number', type: 'integer')]
    protected int $stepNumber = 1;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    protected ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $description = null;

    // --- Getters ---

    public function getForm(): Form
    {
        return $this->form;
    }

    public function getStepNumber(): int
    {
        return $this->stepNumber;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    // --- Setters ---

    public function setForm(Form $form): self
    {
        $this->form = $form;
        return $this;
    }

    public function setStepNumber(int $stepNumber): self
    {
        $this->stepNumber = $stepNumber;
        return $this;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }
}
