<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Models;

use Doctrine\ORM\Mapping as ORM;
use ZephyrPHP\Database\Model;

#[ORM\Entity]
#[ORM\Table(name: 'fb_submissions')]
#[ORM\HasLifecycleCallbacks]
class FormSubmission extends Model
{
    #[ORM\ManyToOne(targetEntity: Form::class)]
    #[ORM\JoinColumn(name: 'form_id', nullable: false, onDelete: 'CASCADE')]
    protected Form $form;

    #[ORM\Column(type: 'json')]
    protected array $data = [];

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $meta = null;

    #[ORM\Column(type: 'string', length: 20)]
    protected string $status = 'completed';

    #[ORM\Column(name: 'payment_id', type: 'string', length: 255, nullable: true)]
    protected ?string $paymentId = null;

    #[ORM\Column(name: 'payment_amount', type: 'integer', nullable: true)]
    protected ?int $paymentAmount = null;

    // --- Getters ---

    public function getForm(): Form
    {
        return $this->form;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getDataValue(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function getMeta(): array
    {
        return $this->meta ?? [];
    }

    public function getMetaValue(string $key, mixed $default = null): mixed
    {
        return $this->getMeta()[$key] ?? $default;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPaymentId(): ?string
    {
        return $this->paymentId;
    }

    public function getPaymentAmount(): ?int
    {
        return $this->paymentAmount;
    }

    public function getPaymentAmountFormatted(): string
    {
        if ($this->paymentAmount === null) {
            return '';
        }
        return number_format($this->paymentAmount / 100, 2);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isPendingPayment(): bool
    {
        return $this->status === 'pending_payment';
    }

    // --- Setters ---

    public function setForm(Form $form): self
    {
        $this->form = $form;
        return $this;
    }

    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function setMeta(?array $meta): self
    {
        $this->meta = $meta;
        return $this;
    }

    public function setStatus(string $status): self
    {
        $this->status = in_array($status, ['completed', 'pending_payment', 'paid', 'failed'])
            ? $status : 'completed';
        return $this;
    }

    public function setPaymentId(?string $paymentId): self
    {
        $this->paymentId = $paymentId;
        return $this;
    }

    public function setPaymentAmount(?int $paymentAmount): self
    {
        $this->paymentAmount = $paymentAmount;
        return $this;
    }
}
