<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A DHCP circuit behind the ISP's NAT can't be ICMP'd on its public IP.
        // Instead we SSH the site's Silver Peak and source a ping out that WAN
        // (`ping <target> -I wanX`): replies mean the circuit passes traffic.
        Schema::table('circuits', function (Blueprint $table) {
            $table->string('monitor_via')->default('icmp')->after('ip_assignment'); // icmp | sdwan
            $table->string('wan_interface')->nullable()->after('monitor_via');       // wan0 | wan1
            $table->string('ping_target')->default('8.8.8.8')->after('wan_interface');
        });
    }

    public function down(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->dropColumn(['monitor_via', 'wan_interface', 'ping_target']);
        });
    }
};
