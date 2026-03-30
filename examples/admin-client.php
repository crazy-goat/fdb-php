<?php

/**
 * Admin Client Example
 *
 * This script demonstrates FoundationDB AdminClient for cluster administration:
 * - Getting admin client via $db->admin()
 * - Getting cluster status
 * - Checking consistency
 * - Note about tenant management
 * - Note about dangerous operations (forceRecovery)
 *
 * Note: Many admin operations require special privileges and may not
 * work in all environments.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\FoundationDB\FoundationDB;

echo "=== FoundationDB Admin Client ===\n\n";

FoundationDB::apiVersion(730);
$db = FoundationDB::open();

// Get admin client
echo "--- Getting Admin Client ---\n";
$admin = $db->admin();
echo "Admin client obtained via \$db->admin()\n\n";

// Cluster status
echo "--- Cluster Status ---\n";
try {
    $status = $admin->getClusterStatus();

    echo "Cluster status retrieved successfully!\n";
    echo "Status keys available:\n";

    // Display top-level status keys
    foreach (array_keys($status) as $key) {
        echo "  - {$key}\n";
    }

    // Show some interesting status info if available
    if (isset($status['client'])) {
        echo "\nClient info:\n";
        if (isset($status['client']['cluster_file'])) {
            echo "  Cluster file: {$status['client']['cluster_file']}\n";
        }
        if (isset($status['client']['database_status'])) {
            echo "  Database status: {$status['client']['database_status']}\n";
        }
    }

    if (isset($status['cluster'])) {
        echo "\nCluster info:\n";
        if (isset($status['cluster']['database_version'])) {
            echo "  Database version: {$status['cluster']['database_version']}\n";
        }
        if (isset($status['cluster']['state'])) {
            echo "  State: " . json_encode($status['cluster']['state']) . "\n";
        }
    }
} catch (\Exception $e) {
    echo "Could not retrieve cluster status: " . $e->getMessage() . "\n";
    echo "(This may require elevated privileges)\n";
}
echo "\n";

// Consistency check
echo "--- Consistency Check ---\n";
try {
    $isConsistent = $admin->consistencyCheck();
    echo "Consistency check result: " . ($isConsistent ? 'Consistent' : 'Inconsistent/Suspended') . "\n";
    echo "Note: Returns true if consistency check is running and database is consistent.\n";
    echo "      Returns false if consistency check is suspended.\n";
} catch (\Exception $e) {
    echo "Could not run consistency check: " . $e->getMessage() . "\n";
}
echo "\n";

// Tenant management
echo "--- Tenant Management ---\n";
echo "FoundationDB supports multi-tenancy through the tenant feature.\n";
echo "Tenants provide isolated namespaces within a cluster.\n\n";

// List existing tenants
echo "Listing existing tenants:\n";
try {
    $tenants = $admin->listTenants();
    if (empty($tenants)) {
        echo "  (No tenants found)\n";
    } else {
        foreach ($tenants as $tenant) {
            echo "  - {$tenant}\n";
        }
    }
} catch (\Exception $e) {
    echo "  Could not list tenants: " . $e->getMessage() . "\n";
}
echo "\n";

// Create a test tenant (may require special privileges)
$testTenantName = 'example_test_tenant';
echo "--- Creating Test Tenant ---\n";
echo "Attempting to create tenant: {$testTenantName}\n";

try {
    $admin->createTenant($testTenantName);
    echo "Tenant created successfully!\n";

    // List tenants again
    $tenants = $admin->listTenants();
    echo "\nTenants after creation:\n";
    foreach ($tenants as $tenant) {
        echo "  - {$tenant}" . ($tenant === $testTenantName ? " (new)" : "") . "\n";
    }

    // Clean up - delete the test tenant
    echo "\nDeleting test tenant...\n";
    $admin->deleteTenant($testTenantName);
    echo "Tenant deleted successfully!\n";
} catch (\Exception $e) {
    echo "Could not create tenant: " . $e->getMessage() . "\n";
    echo "(Tenant management may require special cluster configuration)\n";
}
echo "\n";

// Server management (exclude/include)
echo "--- Server Management ---\n";
echo "AdminClient can exclude and include servers from the cluster:\n";
echo "  - excludeServer(\$address): Remove server from cluster\n";
echo "  - includeServer(\$address): Add server back to cluster\n";
echo "\nExample (not executed):\n";
echo "  \$admin->excludeServer('127.0.0.1:4500');\n";
echo "  \$admin->includeServer('127.0.0.1:4500');\n\n";

// Configuration
echo "--- Database Configuration ---\n";
echo "AdminClient can configure the database:\n";
echo "  - configure(\$configString): Change redundancy/storage mode\n";
echo "\nExample configurations:\n";
echo "  - 'single ssd': Single redundancy, SSD storage\n";
echo "  - 'double ssd': Double redundancy, SSD storage\n";
echo "  - 'triple ssd': Triple redundancy, SSD storage\n";
echo "  - 'single memory': Single redundancy, memory storage\n";
echo "\nExample (not executed):\n";
echo "  \$admin->configure('double ssd');\n\n";

// Dangerous operations warning
echo "--- Dangerous Operations Warning ---\n";
echo "⚠️  The following operations are dangerous and should be used with extreme caution:\n\n";

echo "1. forceRecovery(\$dcId):\n";
echo "   - Forces the database to recover into a specific datacenter\n";
echo "   - May result in DATA LOSS if recent mutations haven't been replicated\n";
echo "   - Only use in emergency situations!\n\n";

echo "2. rebootWorker(\$address):\n";
echo "   - Reboots a FoundationDB worker process\n";
echo "   - Can disrupt cluster operations if not used carefully\n";
echo "   - Use with checkFile=true for safety\n\n";

echo "Example (NOT EXECUTED):\n";
echo "  // DANGEROUS - Only use in emergencies!\n";
echo "  // \$admin->forceRecovery('dc1');\n\n";

// Best practices
echo "--- Admin Operations Best Practices ---\n";
echo "1. Always verify cluster status before making changes\n";
echo "2. Test admin operations in a non-production environment first\n";
echo "3. Use forceRecovery only as a last resort\n";
echo "4. Monitor cluster health after any configuration changes\n";
echo "5. Keep backups before major administrative operations\n";
echo "6. Use special key space options carefully (setSpecialKeySpaceEnableWrites)\n\n";

// Summary
echo "--- Summary ---\n";
echo "AdminClient provides powerful cluster management capabilities:\n";
echo "  ✓ Cluster status monitoring\n";
echo "  ✓ Consistency checking\n";
echo "  ✓ Tenant management\n";
echo "  ✓ Server exclusion/inclusion\n";
echo "  ✓ Database configuration\n";
echo "  ⚠ Emergency operations (use with caution)\n\n";

echo "=== Example Complete ===\n";
