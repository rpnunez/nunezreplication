// Auto-refresh interval (5 seconds)
const REFRESH_INTERVAL = 5000;
let refreshTimer;

// Fetch and display status
async function fetchStatus() {
    try {
        const response = await fetch('/api/status');
        const data = await response.json();
        
        updateStatusIndicator(data);
        updateStats(data.stats);
        updateLastUpdate();
    } catch (error) {
        console.error('Error fetching status:', error);
        showError('statusInfo', 'Failed to fetch status');
    }
}

// Fetch and display configuration
async function fetchConfig() {
    try {
        const response = await fetch('/api/config');
        const data = await response.json();
        
        updateConfigDisplay(data);
        updateTablesDisplay(data.replication?.tables || []);
    } catch (error) {
        console.error('Error fetching config:', error);
        showError('configInfo', 'Failed to fetch configuration');
    }
}

// Update status indicator
function updateStatusIndicator(data) {
    const statusDot = document.getElementById('statusDot');
    const statusText = document.getElementById('statusText');
    
    if (data.status === 'running') {
        statusDot.classList.remove('error');
        statusText.textContent = `Running (${data.mode})`;
    } else {
        statusDot.classList.add('error');
        statusText.textContent = 'Error';
    }
}

// Update statistics display
function updateStats(stats) {
    const statsInfo = document.getElementById('statsInfo');
    
    if (!stats) {
        statsInfo.innerHTML = '<p class="loading">No statistics available yet</p>';
        return;
    }
    
    const successRate = stats.totalSyncs > 0 
        ? Math.round((stats.successfulSyncs / stats.totalSyncs) * 100) 
        : 0;
    
    statsInfo.innerHTML = `
        <div class="info-row">
            <span class="info-label">Last Sync:</span>
            <span class="info-value">${stats.lastSync || 'Never'}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Total Syncs:</span>
            <span class="info-value">${stats.totalSyncs}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Successful:</span>
            <span class="info-value badge badge-success">${stats.successfulSyncs}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Failed:</span>
            <span class="info-value badge ${stats.failedSyncs > 0 ? 'badge-danger' : 'badge-success'}">${stats.failedSyncs}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Success Rate:</span>
            <span class="info-value">${successRate}%</span>
        </div>
        ${stats.lastError ? `
        <div class="info-row">
            <span class="info-label">Last Error:</span>
            <span class="info-value error-message">${stats.lastError}</span>
        </div>
        ` : ''}
    `;
}

// Update configuration display
function updateConfigDisplay(config) {
    const configInfo = document.getElementById('configInfo');
    
    configInfo.innerHTML = `
        <div class="info-row">
            <span class="info-label">Mode:</span>
            <span class="info-value badge badge-primary">${config.mode}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Sync Interval:</span>
            <span class="info-value">${config.syncInterval || 'Manual'}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Master DB:</span>
            <span class="info-value">${config.databases?.master?.host || 'N/A'}:${config.databases?.master?.port || 'N/A'}</span>
        </div>
        ${config.databases?.slave ? `
        <div class="info-row">
            <span class="info-label">Slave DB:</span>
            <span class="info-value">${config.databases.slave.host}:${config.databases.slave.port}</span>
        </div>
        ` : ''}
    `;
}

// Update tables display
function updateTablesDisplay(tables) {
    const tablesInfo = document.getElementById('tablesInfo');
    
    if (!tables || tables.length === 0) {
        tablesInfo.innerHTML = '<p class="loading">No tables configured</p>';
        return;
    }
    
    tablesInfo.innerHTML = tables.map(table => `
        <div class="table-item">
            <div class="table-name">${table.name}</div>
            <div class="table-meta">
                Primary Key: ${table.primaryKey} | 
                Ignored Columns: ${table.ignoreColumns?.length > 0 ? table.ignoreColumns.join(', ') : 'None'}
            </div>
        </div>
    `).join('');
}

// Trigger manual sync
async function triggerSync() {
    const button = document.getElementById('syncButton');
    const resultDiv = document.getElementById('syncResult');
    
    button.disabled = true;
    button.textContent = 'Syncing...';
    resultDiv.className = '';
    resultDiv.style.display = 'none';
    
    try {
        const response = await fetch('/api/sync', { method: 'POST' });
        const data = await response.json();
        
        if (data.success) {
            resultDiv.className = 'success';
            resultDiv.textContent = `✓ Sync completed successfully in ${data.duration}s`;
        } else {
            resultDiv.className = 'error';
            resultDiv.textContent = `✗ Sync failed: ${data.error}`;
        }
        
        // Refresh status after sync
        await fetchStatus();
    } catch (error) {
        resultDiv.className = 'error';
        resultDiv.textContent = `✗ Error: ${error.message}`;
    } finally {
        button.disabled = false;
        button.textContent = 'Trigger Manual Sync';
    }
}

// Update last update timestamp
function updateLastUpdate() {
    const lastUpdate = document.getElementById('lastUpdate');
    lastUpdate.textContent = new Date().toLocaleString();
}

// Show error message
function showError(elementId, message) {
    const element = document.getElementById(elementId);
    element.innerHTML = `<p class="error-message">${message}</p>`;
}

// Initialize dashboard
async function init() {
    await Promise.all([fetchStatus(), fetchConfig()]);
    
    // Set up auto-refresh
    refreshTimer = setInterval(fetchStatus, REFRESH_INTERVAL);
    
    // Set up sync button
    document.getElementById('syncButton').addEventListener('click', triggerSync);
}

// Start the application
document.addEventListener('DOMContentLoaded', init);

// Clean up on page unload
window.addEventListener('beforeunload', () => {
    if (refreshTimer) {
        clearInterval(refreshTimer);
    }
});
