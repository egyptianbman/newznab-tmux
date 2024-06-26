<?php

namespace App\Jobs;

use App\Mail\ContactUs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendContactUsEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $email;

    private $mailTo;

    private $mailBody;

    /**
     * SendContactUsEmail constructor.
     */
    public function __construct($email, $mailTo, $mailBody)
    {
        $this->email = $email;
        $this->mailTo = $mailTo;
        $this->mailBody = $mailBody;
    }

    public function handle(): void
    {
        Mail::to($this->mailTo)->send(new ContactUs($this->mailTo, $this->email, $this->mailBody));
    }
}
