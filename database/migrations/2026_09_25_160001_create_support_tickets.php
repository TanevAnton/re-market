<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support tickets — and what they are NOT.
 *
 * This site already has two other places a person can write to it, and keeping
 * them apart is the whole design constraint here:
 *
 *   `reports`  — DSA Art. 16 notice-and-action. Illegal content, a scam, a
 *                stolen photograph. Legally clocked: receipt must be
 *                acknowledged and a decision given with reasons.
 *   `threads`  — a buyer talking to a seller about an item.
 *   `tickets`  — this. „Your site will not let me upload a photo."
 *
 * A support form that quietly swallows the first of those is the failure mode
 * worth designing against: a notice filed as a help request has no statement of
 * reasons, no clock and no appeal, and nobody finds out until a regulator asks
 * for the numbers. So the form says where those go, and the topics deliberately
 * do not include one that invites them.
 *
 * WHY user_id IS NULLABLE. The person who most needs support is the one locked
 * out of their account, and a form behind a login is useless to exactly them.
 * So a ticket carries an email address of its own — copied from the account
 * when there is one, typed when there is not — and that address, not the
 * account, is who the answer goes to.
 *
 * WHY THERE IS A MESSAGES TABLE rather than a `reply` column. Support is a
 * conversation: an answer usually produces another question, and a single
 * reply field means the second one arrives as a brand-new ticket with none of
 * the context. That is how a queue becomes unworkable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Null for a guest. See the class note — this is the point.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            /*
             * Where the answer goes. Copied from the account at opening time
             * rather than joined, because a user who changes their email
             * mid-conversation should still get the reply to the thread they
             * started — and because for a guest there is nothing to join to.
             */
            $table->string('email', 255);

            $table->string('topic', 32);
            $table->string('subject', 160);
            $table->string('status', 20)->default('new');

            /*
             * Short, unambiguous, and printed in every email about the ticket.
             * Somebody is going to quote it back over the phone.
             */
            $table->string('reference', 24)->unique();

            /*
             * Who is waiting on whom — BY MESSAGE ID, not by timestamp.
             *
             * The first version of this compared three timestamps: has the
             * staff seen_at fallen behind the last reply_at? It does not work,
             * and the reason is that `timestamp` columns here have SECOND
             * precision. Open a ticket and answer it inside the same second —
             * which every test does, and a quick reply in production will — and
             * „strictly after" is false. The badge silently never appears.
             *
             * Message ids have none of that problem. They are integers, they
             * are strictly ordered, and „read up to message 7" is exactly what
             * is being recorded. Compared rather than counted, so there is no
             * unread counter for something to forget to decrement.
             *
             * `last_reply_at` stays, but only as „last activity" for display
             * and ordering. Nothing decides anything on it.
             */
            $table->timestamp('last_reply_at')->nullable();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->unsignedBigInteger('user_seen_message_id')->nullable();
            $table->unsignedBigInteger('staff_seen_message_id')->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The queue's only ordering: what is open, oldest first.
            $table->index(['status', 'created_at']);
            // „My tickets", and the open-ticket cap for one person.
            $table->index(['user_id', 'status']);
            $table->index(['email', 'status']);
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();

            /*
             * Null when the author was a guest — and `from_staff` is what
             * decides which side of the conversation a message is on, NOT
             * whether user_id happens to be an admin. An admin who opens a
             * ticket as a user is writing as a user, and a message whose side
             * is inferred from a role would flip the moment somebody's
             * permissions changed.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('from_staff')->default(false);

            $table->text('body');

            $table->timestamps();

            $table->index(['ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
    }
};
