// Auto-refresh interval (5 seconds)
const REFRESH_INTERVAL = 5000;
let refreshTimer;

// Config editor state
let currentConfigFile = null;
let configSchema = null;
let pendingTemplateId = null;
let pendingTemplateName = null;

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

// Fetch and display sync history
async function fetchSyncHistory() {
    try {
        const response = await fetch('/api/stats/history?limit=10');
        const data = await response.json();
        
        updateSyncHistory(data.history || []);
    } catch (error) {
        console.error('Error fetching sync history:', error);
        showError('syncHistory', 'Failed to fetch sync history');
    }
}

// Fetch and display recent errors
async function fetchRecentErrors() {
    try {
        const response = await fetch('/api/stats/errors?limit=10');
        const data = await response.json();
        
        updateRecentErrors(data.errors || []);
    } catch (error) {
        console.error('Error fetching errors:', error);
        showError('recentErrors', 'Failed to fetch error log');
    }
}

// Fetch per-table statistics
async function fetchPerTableStats() {
    try {
        const response = await fetch('/api/config');
        const configData = await response.json();
        const tables = configData.replication?.tables || [];
        
        if (tables.length > 0) {
            // For demo, show stats for the first table
            const tableName = tables[0].name;
            const statsResponse = await fetch(`/api/stats/table?table=${encodeURIComponent(tableName)}&limit=5`);
            const statsData = await statsResponse.json();
            
            updatePerTableStats(tableName, statsData.stats || []);
        } else {
            showError('perTableStats', 'No tables configured');
        }
    } catch (error) {
        console.error('Error fetching per-table stats:', error);
        showError('perTableStats', 'Failed to fetch table statistics');
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
    
    let html = `
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
    `;
    
    // Add detailed stats if available
    if (stats.totalInserts !== undefined) {
        html += `
        <div class="info-row">
            <span class="info-label">Total Inserts:</span>
            <span class="info-value">${stats.totalInserts}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Total Updates:</span>
            <span class="info-value">${stats.totalUpdates}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Total Deletes:</span>
            <span class="info-value">${stats.totalDeletes}</span>
        </div>
        `;
    }
    
    if (stats.avgDuration !== undefined) {
        html += `
        <div class="info-row">
            <span class="info-label">Avg Duration:</span>
            <span class="info-value">${stats.avgDuration}s</span>
        </div>
        `;
    }
    
    if (stats.lastError) {
        html += `
        <div class="info-row">
            <span class="info-label">Last Error:</span>
            <span class="info-value error-message">${stats.lastError}</span>
        </div>
        `;
    }
    
    statsInfo.innerHTML = html;
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
        await fetchSyncHistory();
        await fetchPerTableStats();
    } catch (error) {
        resultDiv.className = 'error';
        resultDiv.textContent = '✗ Error: Failed to trigger sync. Please try again.';
    } finally {
        button.disabled = false;
        button.textContent = 'Trigger Manual Sync';
    }
}

// Update sync history display
function updateSyncHistory(history) {
    const historyDiv = document.getElementById('syncHistory');
    
    // Clear existing content
    historyDiv.innerHTML = '';
    
    if (!history || history.length === 0) {
        const p = document.createElement('p');
        p.className = 'loading';
        p.textContent = 'No sync history available';
        historyDiv.appendChild(p);
        return;
    }
    
    history.forEach(sync => {
        const statusClass = sync.status === 'success' ? 'badge-success' : 
                           (sync.status === 'failed' ? 'badge-danger' : 'badge-primary');
        const duration = sync.duration_seconds ? `${sync.duration_seconds}s` : 'N/A';

        const itemDiv = document.createElement('div');
        itemDiv.className = 'history-item';

        const headerDiv = document.createElement('div');
        headerDiv.className = 'history-header';

        const statusSpan = document.createElement('span');
        statusSpan.className = `badge ${statusClass}`;
        statusSpan.textContent = sync.status;

        const timeSpan = document.createElement('span');
        timeSpan.className = 'history-time';
        timeSpan.textContent = sync.sync_started_at;

        headerDiv.appendChild(statusSpan);
        headerDiv.appendChild(timeSpan);

        const detailsDiv = document.createElement('div');
        detailsDiv.className = 'history-details';
        detailsDiv.textContent = `Duration: ${duration} | Inserts: ${sync.total_inserts} | Updates: ${sync.total_updates} | Deletes: ${sync.total_deletes}`;

        itemDiv.appendChild(headerDiv);
        itemDiv.appendChild(detailsDiv);

        if (sync.error_message) {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'history-error';
            // Use textContent to avoid interpreting error_message as HTML
            errorDiv.textContent = sync.error_message;
            itemDiv.appendChild(errorDiv);
        }

        historyDiv.appendChild(itemDiv);
    });
}

// Update per-table statistics display
function updatePerTableStats(tableName, stats) {
    const statsDiv = document.getElementById('perTableStats');
    
    // Clear existing content
    statsDiv.innerHTML = '';
    
    if (!stats || stats.length === 0) {
        const p = document.createElement('p');
        p.className = 'loading';
        p.textContent = `No statistics available for ${tableName}`;
        statsDiv.appendChild(p);
        return;
    }
    
    const headerDiv = document.createElement('div');
    headerDiv.className = 'table-stats-header';
    headerDiv.appendChild(document.createTextNode('Stats for table: '));
    
    const tableNameStrong = document.createElement('strong');
    tableNameStrong.textContent = tableName;
    headerDiv.appendChild(tableNameStrong);
    
    statsDiv.appendChild(headerDiv);
    
    stats.forEach(stat => {
        const itemDiv = document.createElement('div');
        itemDiv.className = 'table-stat-item';

        const timeDiv = document.createElement('div');
        timeDiv.className = 'stat-time';
        timeDiv.textContent = stat.sync_timestamp;

        const detailsDiv = document.createElement('div');
        detailsDiv.className = 'stat-details';
        detailsDiv.textContent = `Rows: ${stat.rows_processed} | Inserts: ${stat.inserts} | Updates: ${stat.updates} | Deletes: ${stat.deletes}`;

        itemDiv.appendChild(timeDiv);
        itemDiv.appendChild(detailsDiv);

        statsDiv.appendChild(itemDiv);
    });
}

// Update recent errors display
function updateRecentErrors(errors) {
    const errorsDiv = document.getElementById('recentErrors');
    
    // Clear existing content
    errorsDiv.innerHTML = '';
    
    if (!errors || errors.length === 0) {
        const p = document.createElement('p');
        p.className = 'loading';
        p.textContent = 'No recent errors';
        errorsDiv.appendChild(p);
        return;
    }
    
    errors.forEach(error => {
        const itemDiv = document.createElement('div');
        itemDiv.className = 'error-item';

        const timeDiv = document.createElement('div');
        timeDiv.className = 'error-time';
        timeDiv.textContent = error.log_timestamp;

        const messageDiv = document.createElement('div');
        messageDiv.className = 'error-message';
        // Use textContent to avoid interpreting message as HTML
        messageDiv.textContent = error.message;

        itemDiv.appendChild(timeDiv);
        itemDiv.appendChild(messageDiv);

        errorsDiv.appendChild(itemDiv);
    });
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
    await Promise.all([
        fetchStatus(), 
        fetchConfig(), 
        fetchSyncHistory(), 
        fetchPerTableStats(),
        fetchRecentErrors()
    ]);
    
    // Initialize config editor
    await initConfigEditor();
    
    // Initialize data management
    await initDataManagement();
    
    // Set up auto-refresh (only refresh status and history)
    refreshTimer = setInterval(() => {
        fetchStatus();
        fetchSyncHistory();
        fetchRecentErrors();
    }, REFRESH_INTERVAL);
    
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

// ============================================
// CONFIG EDITOR FUNCTIONALITY
// ============================================

// Show notification toast
function showNotification(message, type = 'success') {
    const toast = document.getElementById('notificationToast');
    toast.textContent = message;
    toast.className = `toast ${type} show`;
    
    setTimeout(() => {
        toast.classList.remove('show');
    }, 4000);
}

// Fetch and display config files list
async function fetchConfigsList() {
    try {
        const response = await fetch('/api/configs');
        const data = await response.json();
        
        if (data.success) {
            updateConfigsList(data.configs);
        } else {
            showError('configsList', 'Failed to load configurations');
        }
    } catch (error) {
        console.error('Error fetching configs:', error);
        showError('configsList', 'Failed to load configurations');
    }
}

// Update configs list display
function updateConfigsList(configs) {
    const configsList = document.getElementById('configsList');
    configsList.innerHTML = '';
    
    if (!configs || configs.length === 0) {
        configsList.innerHTML = '<p class="loading">No configuration files found</p>';
        return;
    }
    
    configs.forEach(config => {
        const itemDiv = document.createElement('div');
        itemDiv.className = 'config-file-item';
        
        const infoDiv = document.createElement('div');
        infoDiv.className = 'config-file-info';
        
        const nameDiv = document.createElement('div');
        nameDiv.className = 'config-filename';
        nameDiv.textContent = config.filename;
        
        const metaDiv = document.createElement('div');
        metaDiv.className = 'config-meta';
        const date = new Date(config.modified * 1000);
        metaDiv.textContent = `Size: ${(config.size / 1024).toFixed(2)} KB | Modified: ${date.toLocaleString()}`;
        
        infoDiv.appendChild(nameDiv);
        infoDiv.appendChild(metaDiv);
        
        const actionsDiv = document.createElement('div');
        actionsDiv.className = 'config-file-actions';
        
        const editBtn = document.createElement('button');
        editBtn.className = 'btn btn-sm btn-primary';
        editBtn.textContent = 'Edit';
        editBtn.onclick = () => openConfigEditor(config.filename);
        
        actionsDiv.appendChild(editBtn);
        
        itemDiv.appendChild(infoDiv);
        itemDiv.appendChild(actionsDiv);
        
        configsList.appendChild(itemDiv);
    });
}

// Open config editor modal
async function openConfigEditor(filename) {
    try {
        const response = await fetch(`/api/configs/file?filename=${encodeURIComponent(filename)}`);
        const data = await response.json();
        
        if (!data.success) {
            showNotification('Failed to load config: ' + data.error, 'error');
            return;
        }
        
        currentConfigFile = filename;
        
        // Load schema if not already loaded
        if (!configSchema) {
            const schemaResponse = await fetch('/api/configs/schema');
            const schemaData = await schemaResponse.json();
            if (schemaData.success) {
                configSchema = schemaData.schema;
            }
        }
        
        // Set editor content
        document.getElementById('configFilename').value = filename;
        document.getElementById('configEditor').value = data.config.content;
        document.getElementById('modalTitle').textContent = 'Edit Configuration: ' + filename;
        
        // Update help panel
        updateConfigHelp();
        
        // Show modal
        document.getElementById('configEditorModal').classList.add('active');
        
        // Clear status
        document.getElementById('editorStatus').textContent = '';
        document.getElementById('editorStatus').className = 'editor-status';
        
    } catch (error) {
        console.error('Error opening config editor:', error);
        showNotification('Failed to open config editor', 'error');
    }
}

// Update config help panel
function updateConfigHelp() {
    const helpDiv = document.getElementById('configHelp');
    
    if (!configSchema) {
        helpDiv.innerHTML = '<p>Loading help...</p>';
        return;
    }
    
    let html = '<div class="help-intro"><p><strong>Configuration Reference</strong></p><p>All available configuration options:</p></div>';
    
    Object.keys(configSchema).forEach(key => {
        const field = configSchema[key];
        html += `
            <div class="help-section">
                <div class="help-key">${key}</div>
                <div class="help-type">${field.type}${field.required ? ' (required)' : ' (optional)'}</div>
                <div class="help-description">${field.description}</div>
        `;
        
        if (field.values) {
            html += `<div class="help-values"><strong>Allowed values:</strong> ${field.values.join(', ')}</div>`;
        }
        
        if (field.default !== undefined) {
            html += `<div class="help-values"><strong>Default:</strong> ${JSON.stringify(field.default)}</div>`;
        }
        
        if (field.properties) {
            html += '<div class="help-values"><strong>Properties:</strong><ul>';
            Object.keys(field.properties).forEach(prop => {
                html += `<li><code>${prop}</code>: ${field.properties[prop]}</li>`;
            });
            html += '</ul></div>';
        }
        
        html += '</div>';
    });
    
    // Add examples section
    html += `
        <div class="help-section">
            <h4>Example Configuration</h4>
            <div class="help-example">{
  "mode": "master-slave",
  "syncInterval": "*/5 * * * *",
  "databases": {
    "master": {
      "host": "localhost",
      "port": 3306,
      "user": "root",
      "password": "password",
      "database": "master_db"
    },
    "slave": {
      "host": "localhost",
      "port": 3307,
      "user": "root",
      "password": "password",
      "database": "slave_db"
    }
  },
  "replication": {
    "tables": [{
      "name": "users",
      "primaryKey": "id",
      "timestampColumn": "updated_at"
    }]
  }
}</div>
        </div>
    `;
    
    helpDiv.innerHTML = html;
}

// Save config file
async function saveConfigFile() {
    const filename = document.getElementById('configFilename').value;
    const content = document.getElementById('configEditor').value;
    const statusDiv = document.getElementById('editorStatus');
    
    // Validate filename
    if (!filename) {
        statusDiv.className = 'editor-status error';
        statusDiv.textContent = 'Please enter a filename';
        return;
    }
    
    // Show saving status
    statusDiv.className = 'editor-status';
    statusDiv.textContent = 'Saving...';
    
    try {
        const response = await fetch('/api/configs/save', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ filename, content })
        });
        
        const data = await response.json();
        
        if (data.success) {
            statusDiv.className = 'editor-status success';
            statusDiv.textContent = '✓ Configuration saved successfully!';
            
            // Refresh configs list
            await fetchConfigsList();
            
            // Close modal after delay
            setTimeout(() => {
                closeConfigEditorModal();
            }, 1500);
        } else {
            statusDiv.className = 'editor-status error';
            statusDiv.textContent = '✗ Error: ' + data.error;
        }
    } catch (error) {
        statusDiv.className = 'editor-status error';
        statusDiv.textContent = '✗ Failed to save configuration';
        console.error('Error saving config:', error);
    }
}

// Open create config modal
async function openCreateConfigModal() {
    try {
        const response = await fetch('/api/configs/templates');
        const data = await response.json();
        
        if (!data.success) {
            showNotification('Failed to load templates', 'error');
            return;
        }
        
        const templatesDiv = document.getElementById('templatesList');
        templatesDiv.innerHTML = '';
        
        data.templates.forEach(template => {
            const itemDiv = document.createElement('div');
            itemDiv.className = 'template-item';
            itemDiv.onclick = () => createFromTemplate(template.id, template.name);
            
            const nameDiv = document.createElement('div');
            nameDiv.className = 'template-name';
            nameDiv.textContent = template.name;
            
            const descDiv = document.createElement('div');
            descDiv.className = 'template-description';
            descDiv.textContent = template.description;
            
            itemDiv.appendChild(nameDiv);
            itemDiv.appendChild(descDiv);
            templatesDiv.appendChild(itemDiv);
        });
        
        document.getElementById('createConfigModal').classList.add('active');
    } catch (error) {
        console.error('Error loading templates:', error);
        showNotification('Failed to load templates', 'error');
    }
}

// Create config from template
function createFromTemplate(templateId, templateName) {
    // Store template info and show filename prompt modal
    pendingTemplateId = templateId;
    pendingTemplateName = templateName;
    
    // Close create modal
    document.getElementById('createConfigModal').classList.remove('active');
    
    // Set default filename with same format as PHP: Y-m-d-His
    const now = new Date();
    const timestamp = now.toISOString().slice(0, 10) + '-' + 
                     now.toISOString().slice(11, 19).replace(/:/g, '');
    document.getElementById('newFilenameInput').value = `config.${templateId}.${timestamp}.json`;
    
    // Clear error
    document.getElementById('filenameError').textContent = '';
    document.getElementById('filenameError').className = 'editor-status';
    
    // Show filename prompt modal
    document.getElementById('filenamePromptModal').classList.add('active');
    document.getElementById('newFilenameInput').focus();
}

// Confirm filename and create config
async function confirmFilename() {
    const filename = document.getElementById('newFilenameInput').value.trim();
    const errorDiv = document.getElementById('filenameError');
    
    if (!filename) {
        errorDiv.className = 'editor-status error';
        errorDiv.textContent = 'Please enter a filename';
        return;
    }
    
    // Validate filename format - must start with 'config'
    if (!filename.match(/^config[a-zA-Z0-9]*([._-]?[a-zA-Z0-9]+)*\.json$/)) {
        errorDiv.className = 'editor-status error';
        errorDiv.textContent = 'Invalid filename. Must start with "config" and use only alphanumeric characters with optional separators (._-)';
        return;
    }
    
    try {
        const response = await fetch('/api/configs/create', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ type: pendingTemplateId, filename })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Close filename prompt modal
            document.getElementById('filenamePromptModal').classList.remove('active');
            
            // Show success notification
            showNotification('Configuration created successfully!', 'success');
            
            // Refresh list
            await fetchConfigsList();
            
            // Open editor with new file
            await openConfigEditor(data.result.filename);
        } else {
            errorDiv.className = 'editor-status error';
            errorDiv.textContent = data.error;
        }
    } catch (error) {
        console.error('Error creating config:', error);
        errorDiv.className = 'editor-status error';
        errorDiv.textContent = 'Failed to create configuration';
    }
}

// Close filename prompt modal
function closeFilenamePromptModal() {
    document.getElementById('filenamePromptModal').classList.remove('active');
    pendingTemplateId = null;
    pendingTemplateName = null;
}

// Close modals
function closeConfigEditorModal() {
    document.getElementById('configEditorModal').classList.remove('active');
    currentConfigFile = null;
}

function closeCreateConfigModal() {
    document.getElementById('createConfigModal').classList.remove('active');
}

// Initialize config editor when page loads
async function initConfigEditor() {
    // Fetch configs list
    await fetchConfigsList();
    
    // Set up event listeners
    document.getElementById('createConfigButton').addEventListener('click', openCreateConfigModal);
    document.getElementById('saveConfigButton').addEventListener('click', saveConfigFile);
    document.getElementById('cancelConfigButton').addEventListener('click', closeConfigEditorModal);
    document.getElementById('confirmFilenameButton').addEventListener('click', confirmFilename);
    document.getElementById('cancelFilenameButton').addEventListener('click', closeFilenamePromptModal);
    
    // Close modals on X click
    document.querySelectorAll('.modal .close').forEach(closeBtn => {
        closeBtn.addEventListener('click', (e) => {
            e.target.closest('.modal').classList.remove('active');
        });
    });
    
    // Close modals on outside click
    window.addEventListener('click', (e) => {
        if (e.target.classList.contains('modal')) {
            e.target.classList.remove('active');
        }
    });
    
    // Close modals on Escape key for keyboard accessibility
    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.active').forEach(modal => {
                modal.classList.remove('active');
            });
        }
    });
    
    // Handle Enter key in filename input
    document.getElementById('newFilenameInput').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            confirmFilename();
        }
    });
}

// Data Management Functions

async function openGenerateDataModal() {
    const modal = document.getElementById('generateDataModal');
    const databaseSelect = document.getElementById('generateDatabaseSelect');
    const tablesContainer = document.getElementById('generateTablesContainer');
    const confirmButton = document.getElementById('generateDataConfirmButton');
    const statusDiv = document.getElementById('generateStatus');
    
    // Reset
    tablesContainer.style.display = 'none';
    confirmButton.disabled = true;
    statusDiv.textContent = '';
    statusDiv.className = 'editor-status';
    
    // Load databases
    try {
        const response = await fetch('/api/data/databases');
        const data = await response.json();
        
        if (data.success) {
            databaseSelect.innerHTML = '<option value="">-- Select a database --</option>';
            data.databases.forEach(db => {
                const option = document.createElement('option');
                option.value = db.name;
                option.textContent = `${db.name} (${db.database})`;
                databaseSelect.appendChild(option);
            });
        } else {
            databaseSelect.innerHTML = '<option value="">Failed to load databases</option>';
        }
    } catch (error) {
        console.error('Error loading databases:', error);
        databaseSelect.innerHTML = '<option value="">Error loading databases</option>';
    }
    
    modal.classList.add('active');
}

async function onGenerateDatabaseChange() {
    const databaseSelect = document.getElementById('generateDatabaseSelect');
    const tablesContainer = document.getElementById('generateTablesContainer');
    const tablesList = document.getElementById('generateTablesList');
    const confirmButton = document.getElementById('generateDataConfirmButton');
    const statusDiv = document.getElementById('generateStatus');
    
    const selectedDb = databaseSelect.value;
    
    if (!selectedDb) {
        tablesContainer.style.display = 'none';
        confirmButton.disabled = true;
        return;
    }
    
    statusDiv.textContent = 'Loading tables...';
    statusDiv.className = 'editor-status';
    
    try {
        const response = await fetch(`/api/data/tables?database=${encodeURIComponent(selectedDb)}`);
        const data = await response.json();
        
        if (data.success) {
            tablesList.innerHTML = '';
            
            data.tables.forEach(table => {
                const row = document.createElement('div');
                row.className = 'table-input-row';
                
                const label = document.createElement('span');
                label.className = 'table-input-label';
                label.textContent = table;
                
                const input = document.createElement('input');
                input.type = 'number';
                input.className = 'table-input-field';
                input.min = '0';
                input.value = '0';
                input.placeholder = 'Rows';
                input.dataset.table = table;
                
                row.appendChild(label);
                row.appendChild(input);
                tablesList.appendChild(row);
            });
            
            tablesContainer.style.display = 'block';
            confirmButton.disabled = false;
            statusDiv.textContent = '';
        } else {
            statusDiv.textContent = data.error || 'Failed to load tables';
            statusDiv.className = 'editor-status error';
        }
    } catch (error) {
        console.error('Error loading tables:', error);
        statusDiv.textContent = 'Error loading tables';
        statusDiv.className = 'editor-status error';
    }
}

async function confirmGenerateData() {
    const databaseSelect = document.getElementById('generateDatabaseSelect');
    const tablesList = document.getElementById('generateTablesList');
    const confirmButton = document.getElementById('generateDataConfirmButton');
    const statusDiv = document.getElementById('generateStatus');
    
    const selectedDb = databaseSelect.value;
    
    if (!selectedDb) {
        statusDiv.textContent = 'Please select a database';
        statusDiv.className = 'editor-status error';
        return;
    }
    
    // Collect table row counts
    const tables = {};
    const inputs = tablesList.querySelectorAll('.table-input-field');
    
    inputs.forEach(input => {
        const count = parseInt(input.value) || 0;
        if (count > 0) {
            tables[input.dataset.table] = count;
        }
    });
    
    if (Object.keys(tables).length === 0) {
        statusDiv.textContent = 'Please specify at least one table with row count > 0';
        statusDiv.className = 'editor-status error';
        return;
    }
    
    confirmButton.disabled = true;
    statusDiv.textContent = 'Generating data...';
    statusDiv.className = 'editor-status';
    
    try {
        const response = await fetch('/api/data/generate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                database: selectedDb,
                tables: tables
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            statusDiv.textContent = 'Data generated successfully!';
            statusDiv.className = 'editor-status success';
            
            showNotification('Data generated successfully!', 'success');
            
            setTimeout(() => {
                closeGenerateDataModal();
            }, 2000);
        } else {
            statusDiv.textContent = data.error || 'Failed to generate data';
            statusDiv.className = 'editor-status error';
            confirmButton.disabled = false;
        }
    } catch (error) {
        console.error('Error generating data:', error);
        statusDiv.textContent = 'Error generating data';
        statusDiv.className = 'editor-status error';
        confirmButton.disabled = false;
    }
}

function closeGenerateDataModal() {
    document.getElementById('generateDataModal').classList.remove('active');
}

async function openIntroduceUpdatesModal() {
    const modal = document.getElementById('introduceUpdatesModal');
    const databaseSelect = document.getElementById('updateDatabaseSelect');
    const tablesContainer = document.getElementById('updateTablesContainer');
    const confirmButton = document.getElementById('updateDataConfirmButton');
    const statusDiv = document.getElementById('updateStatus');
    
    // Reset
    tablesContainer.style.display = 'none';
    confirmButton.disabled = true;
    statusDiv.textContent = '';
    statusDiv.className = 'editor-status';
    
    // Load databases
    try {
        const response = await fetch('/api/data/databases');
        const data = await response.json();
        
        if (data.success) {
            databaseSelect.innerHTML = '<option value="">-- Select a database --</option>';
            data.databases.forEach(db => {
                const option = document.createElement('option');
                option.value = db.name;
                option.textContent = `${db.name} (${db.database})`;
                databaseSelect.appendChild(option);
            });
        } else {
            databaseSelect.innerHTML = '<option value="">Failed to load databases</option>';
        }
    } catch (error) {
        console.error('Error loading databases:', error);
        databaseSelect.innerHTML = '<option value="">Error loading databases</option>';
    }
    
    modal.classList.add('active');
}

async function onUpdateDatabaseChange() {
    const databaseSelect = document.getElementById('updateDatabaseSelect');
    const tablesContainer = document.getElementById('updateTablesContainer');
    const tablesList = document.getElementById('updateTablesList');
    const confirmButton = document.getElementById('updateDataConfirmButton');
    const statusDiv = document.getElementById('updateStatus');
    
    const selectedDb = databaseSelect.value;
    
    if (!selectedDb) {
        tablesContainer.style.display = 'none';
        confirmButton.disabled = true;
        return;
    }
    
    statusDiv.textContent = 'Loading tables...';
    statusDiv.className = 'editor-status';
    
    try {
        const response = await fetch(`/api/data/tables?database=${encodeURIComponent(selectedDb)}`);
        const data = await response.json();
        
        if (data.success) {
            tablesList.innerHTML = '';
            
            data.tables.forEach(table => {
                const row = document.createElement('div');
                row.className = 'table-input-row';
                
                const label = document.createElement('span');
                label.className = 'table-input-label';
                label.textContent = table;
                
                const input = document.createElement('input');
                input.type = 'number';
                input.className = 'table-input-field';
                input.min = '0';
                input.value = '0';
                input.placeholder = 'Rows';
                input.dataset.table = table;
                
                row.appendChild(label);
                row.appendChild(input);
                tablesList.appendChild(row);
            });
            
            tablesContainer.style.display = 'block';
            confirmButton.disabled = false;
            statusDiv.textContent = '';
        } else {
            statusDiv.textContent = data.error || 'Failed to load tables';
            statusDiv.className = 'editor-status error';
        }
    } catch (error) {
        console.error('Error loading tables:', error);
        statusDiv.textContent = 'Error loading tables';
        statusDiv.className = 'editor-status error';
    }
}

async function confirmIntroduceUpdates() {
    const databaseSelect = document.getElementById('updateDatabaseSelect');
    const tablesList = document.getElementById('updateTablesList');
    const confirmButton = document.getElementById('updateDataConfirmButton');
    const statusDiv = document.getElementById('updateStatus');
    
    const selectedDb = databaseSelect.value;
    
    if (!selectedDb) {
        statusDiv.textContent = 'Please select a database';
        statusDiv.className = 'editor-status error';
        return;
    }
    
    // Collect table update counts
    const tables = {};
    const inputs = tablesList.querySelectorAll('.table-input-field');
    
    inputs.forEach(input => {
        const count = parseInt(input.value) || 0;
        if (count > 0) {
            tables[input.dataset.table] = count;
        }
    });
    
    if (Object.keys(tables).length === 0) {
        statusDiv.textContent = 'Please specify at least one table with row count > 0';
        statusDiv.className = 'editor-status error';
        return;
    }
    
    confirmButton.disabled = true;
    statusDiv.textContent = 'Updating data...';
    statusDiv.className = 'editor-status';
    
    try {
        const response = await fetch('/api/data/update', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                database: selectedDb,
                tables: tables
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            statusDiv.textContent = 'Data updated successfully!';
            statusDiv.className = 'editor-status success';
            
            showNotification('Data updated successfully!', 'success');
            
            setTimeout(() => {
                closeIntroduceUpdatesModal();
            }, 2000);
        } else {
            statusDiv.textContent = data.error || 'Failed to update data';
            statusDiv.className = 'editor-status error';
            confirmButton.disabled = false;
        }
    } catch (error) {
        console.error('Error updating data:', error);
        statusDiv.textContent = 'Error updating data';
        statusDiv.className = 'editor-status error';
        confirmButton.disabled = false;
    }
}

function closeIntroduceUpdatesModal() {
    document.getElementById('introduceUpdatesModal').classList.remove('active');
}

// Initialize data management when page loads
async function initDataManagement() {
    // Set up event listeners
    document.getElementById('generateDataButton').addEventListener('click', openGenerateDataModal);
    document.getElementById('introduceUpdatesButton').addEventListener('click', openIntroduceUpdatesModal);
    
    document.getElementById('generateDatabaseSelect').addEventListener('change', onGenerateDatabaseChange);
    document.getElementById('updateDatabaseSelect').addEventListener('change', onUpdateDatabaseChange);
    
    document.getElementById('generateDataConfirmButton').addEventListener('click', confirmGenerateData);
    document.getElementById('generateDataCancelButton').addEventListener('click', closeGenerateDataModal);
    
    document.getElementById('updateDataConfirmButton').addEventListener('click', confirmIntroduceUpdates);
    document.getElementById('updateDataCancelButton').addEventListener('click', closeIntroduceUpdatesModal);
}
