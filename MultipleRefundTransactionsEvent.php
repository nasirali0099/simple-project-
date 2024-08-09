<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MultipleRefundTransactionsEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message = '';
    public $type = '';
    public $step = '';
    public $authId = '';
    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($message, $type, $step, $authId)
    {
        $this->message = $message;
        $this->type = $type;
        $this->step = $step;
        $this->authId = $authId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('multiple-refunds-'.$this->authId);
    }
}
