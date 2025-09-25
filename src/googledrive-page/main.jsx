import { createRoot, render, StrictMode, useState, useEffect, createInterpolateElement } from '@wordpress/element';
import { Button, TextControl, Spinner, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import "./scss/style.scss"

const domElement = document.getElementById( window.wpmudevDriveTest.dom_element_id );

const WPMUDEV_DriveTest = () => {
    const [isAuthenticated, setIsAuthenticated] = useState(window.wpmudevDriveTest.authStatus || false);
    const [hasCredentials, setHasCredentials] = useState(window.wpmudevDriveTest.hasCredentials || false);
    const [showCredentials, setShowCredentials] = useState(!window.wpmudevDriveTest.hasCredentials);
    const [isLoading, setIsLoading] = useState(false);
    const [files, setFiles] = useState([]);
    const [nextPageToken, setNextPageToken] = useState(null);
    const [uploadFile, setUploadFile] = useState(null);
    const [folderName, setFolderName] = useState('');
    const [notice, setNotice] = useState({ message: '', type: '' });
    const [uploadProgress, setUploadProgress] = useState(0);
    const [isUploading, setIsUploading] = useState(false);
    const [isCreatingFolder, setIsCreatingFolder] = useState(false);
    const [isLoadingFiles, setIsLoadingFiles] = useState(false);
    const [credentials, setCredentials] = useState({
        clientId: '',
        clientSecret: ''
    });

    // Keep the credentials form visibility in sync with current credentials state.
    useEffect(() => {
        setShowCredentials(!hasCredentials);
    }, [hasCredentials]);

    // Handle OAuth redirect query params: ?auth=success or ?auth=failed (or ?error=...).
    useEffect(() => {
        try {
            const url = new URL(window.location.href);
            const auth = url.searchParams.get('auth');
            const error = url.searchParams.get('error');

            if (auth === 'success') {
                setIsAuthenticated(true);
                showNotice(__('Successfully authenticated with Google Drive.', 'wpmudev-plugin-test'), 'success');
                // Clean the URL
                window.history.replaceState(null, '', url.pathname + url.hash);
            } else if (auth === 'failed') {
                const msg = error ? decodeURIComponent(error) : __('Authentication failed. Please try again.', 'wpmudev-plugin-test');
                showNotice(msg, 'error');
                window.history.replaceState(null, '', url.pathname + url.hash);
            }
        } catch (e) {
            // no-op
        }
    }, []);

    useEffect(() => {
     }, []);

    // Auto-load files once authenticated (initial mount OR when auth state flips).
    useEffect(() => {
        if (isAuthenticated) {
            loadFiles(false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isAuthenticated]);

    const showNotice = (message, type = 'success') => {
        setNotice({ message, type });
        setTimeout(() => setNotice({ message: '', type: '' }), 6000);
    };

    // To be implemented in tasks 2.2+.
    // Inside WPMUDEV_DriveTest component, replace the placeholder with this:
    const handleSaveCredentials = async () => {
    const clientId = (credentials.clientId || '').trim();
    const clientSecret = (credentials.clientSecret || '').trim();

    if (!clientId || !clientSecret) {
        showNotice(__('Please enter Client ID and Client Secret.', 'wpmudev-plugin-test'), 'error');
        return;
    }

    setIsLoading(true);
    try {
        const res = await apiFetch({
        path: '/' + window.wpmudevDriveTest.restEndpointSave,
        method: 'POST',
        headers: { 'X-WP-Nonce': window.wpmudevDriveTest.nonce },
        data: { client_id: clientId, client_secret: clientSecret },
        });

        if (!res?.success) {
        throw new Error(res?.message || __('Failed to save credentials.', 'wpmudev-plugin-test'));
        }

        setHasCredentials(true);
        setShowCredentials(false);
        setCredentials({ clientId: '', clientSecret: '' });
        showNotice(__('Credentials saved. You can now authenticate with Google Drive.', 'wpmudev-plugin-test'), 'success');
    } catch (e) {
        showNotice(e?.message || __('Failed to save credentials. Please try again.', 'wpmudev-plugin-test'), 'error');
    } finally {
        setIsLoading(false);
    }
    };

    const formatSize = (bytes) => {
        if (!bytes) return '—';
        const units = ['B','KB','MB','GB','TB'];
        let i = 0;
        let n = bytes;
        while (n >= 1024 && i < units.length - 1) {
            n /= 1024;
            i++;
        }
        return `${n.toFixed( (i===0)?0:1 )} ${units[i]}`;
    };

    // 2.3: Start OAuth 2.0 flow by requesting the consent URL and redirecting.
    const handleAuth = async () => {
        setIsLoading(true);
        try {
            const result = await apiFetch({
                path: '/' + window.wpmudevDriveTest.restEndpointAuth,
                method: 'POST',
                headers: {
                    'X-WP-Nonce': window.wpmudevDriveTest.nonce,
                },
            });

            const authUrl = result?.auth_url || result?.data?.auth_url;
            if (!authUrl) {
                throw new Error(__('Could not obtain authorization URL.', 'wpmudev-plugin-test'));
            }

            // Redirect the browser to Google's consent screen.
            window.location.href = authUrl;
        } catch (err) {
            const msg = err?.message || __('Failed to initiate authentication.', 'wpmudev-plugin-test');
            showNotice(msg, 'error');
        } finally {
            setIsLoading(false);
        }
    };

    const loadFiles = async (append = false, pageToken = null) => {
        setIsLoading(true);
        setIsLoadingFiles(true);
        try {
            const params = new URLSearchParams();
            params.set('pageSize', 20);
            if (pageToken) {
                params.set('pageToken', pageToken);
            }
            const result = await apiFetch({
                path: '/' + window.wpmudevDriveTest.restEndpointFiles + '?' + params.toString(),
                method: 'GET',
                headers: {
                    'X-WP-Nonce': window.wpmudevDriveTest.nonce,
                },
            });

            if (!result?.success) {
                throw new Error(result?.message || __('Failed to load files.', 'wpmudev-plugin-test'));
            }

            setNextPageToken(result.nextPageToken || null);
            setFiles((prev) => append ? [...prev, ...result.files] : result.files);
        } catch (e) {
            showNotice(e?.message || __('Unable to fetch Google Drive files.', 'wpmudev-plugin-test'), 'error');
        } finally {
            setIsLoading(false);
            setIsLoadingFiles(false);
        }
    };
    const handleUpload = async () => {
        if (!uploadFile) return;
        // Example validation: limit to 25MB
        const maxBytes = 25 * 1024 * 1024;
        if (uploadFile.size > maxBytes) {
            showNotice(__('File exceeds 25MB limit for this test.', 'wpmudev-plugin-test'), 'error');
            return;
        }

        setIsUploading(true);
        setUploadProgress(0);
        setIsLoading(true);

        try {
            const formData = new FormData();
            formData.append('file', uploadFile);

            // Use XMLHttpRequest to track progress (apiFetch does not expose upload progress).
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.origin + '/wp-json/' + window.wpmudevDriveTest.restEndpointUpload);
            xhr.setRequestHeader('X-WP-Nonce', window.wpmudevDriveTest.nonce);

            xhr.upload.onprogress = (evt) => {
                if (evt.lengthComputable) {
                    const pct = Math.round((evt.loaded / evt.total) * 100);
                    setUploadProgress(pct);
                }
            };

            const response = await new Promise((resolve, reject) => {
                xhr.onreadystatechange = () => {
                    if (xhr.readyState === 4) {
                        if (xhr.status >= 200 && xhr.status < 300) {
                            try {
                                resolve(JSON.parse(xhr.responseText));
                            } catch (err) {
                                reject(err);
                            }
                        } else {
                            reject(new Error(xhr.statusText || 'Upload failed'));
                        }
                    }
                };
                xhr.onerror = () => reject(new Error('Network error during upload'));
                xhr.send(formData);
            });

            if (!response?.success) {
                throw new Error(response?.message || __('Upload failed.', 'wpmudev-plugin-test'));
            }

            showNotice(__('File uploaded successfully.', 'wpmudev-plugin-test'), 'success');
            setUploadFile(null);
            setUploadProgress(0);
            // Refresh list
            await loadFiles(false);
        } catch (e) {
            showNotice(e?.message || __('File upload failed.', 'wpmudev-plugin-test'), 'error');
        } finally {
            setIsUploading(false);
            setIsLoading(false);
        }
    };
    const handleDownload = async (fileId, fileName) => {
        if (!fileId) return;
        setIsLoading(true);
        try {
            const params = new URLSearchParams();
            params.set('file_id', fileId);

            const result = await apiFetch({
                path: '/' + window.wpmudevDriveTest.restEndpointDownload + '?' + params.toString(),
                method: 'GET',
                headers: {
                    'X-WP-Nonce': window.wpmudevDriveTest.nonce,
                },
            });

            if (!result?.success || !result?.content) {
                throw new Error(result?.message || __('Download failed.', 'wpmudev-plugin-test'));
            }

            const binary = atob(result.content);
            const len = binary.length;
            const bytes = new Uint8Array(len);
            for (let i = 0; i < len; i++) {
                bytes[i] = binary.charCodeAt(i);
            }
            const blob = new Blob([bytes], { type: result.mimeType || 'application/octet-stream' });
            const url = URL.createObjectURL(blob);

            const a = document.createElement('a');
            a.href = url;
            a.download = fileName || result.filename || 'download';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
            showNotice(__('File downloaded.', 'wpmudev-plugin-test'), 'success');
        } catch (e) {
            showNotice(e?.message || __('Unable to download file.', 'wpmudev-plugin-test'), 'error');
        } finally {
            setIsLoading(false);
        }
    };
    const handleCreateFolder = async () => {
        const name = (folderName || '').trim();
        if (!name) return;

        setIsCreatingFolder(true);
        setIsLoading(true);
        try {
            const result = await apiFetch({
                path: '/' + window.wpmudevDriveTest.restEndpointCreate,
                method: 'POST',
                headers: {
                    'X-WP-Nonce': window.wpmudevDriveTest.nonce,
                },
                data: { name },
            });

            if (!result?.success) {
                throw new Error(result?.message || __('Failed to create folder.', 'wpmudev-plugin-test'));
            }

            showNotice(__('Folder created successfully.', 'wpmudev-plugin-test'), 'success');
            setFolderName('');
            await loadFiles(false);
        } catch (e) {
            showNotice(e?.message || __('Unable to create folder.', 'wpmudev-plugin-test'), 'error');
        } finally {
            setIsCreatingFolder(false);
            setIsLoading(false);
        }
    };

    return (
        <>
            <div className="sui-header">
                <h1 className="sui-header-title">
                    { __( 'Google Drive Test', 'wpmudev-plugin-test' ) }
                </h1>
                <p className="sui-description">
                    { __( 'Test Google Drive API integration for applicant assessment', 'wpmudev-plugin-test' ) }
                </p>
            </div>

            {notice.message && (
                <Notice
                    status={notice.type}
                    isDismissible
                    onRemove={() => setNotice({ message: '', type: '' })}
                >
                    {notice.message}
                </Notice>
            )}

            {showCredentials ? (
                <div className="sui-box">
                    <div className="sui-box-header">
                        <h2 className="sui-box-title">{ __( 'Set Google Drive Credentials', 'wpmudev-plugin-test' ) }</h2>
                    </div>
                    <div className="sui-box-body">
                        <div className="sui-box-settings-row">
                            <TextControl
                                help={createInterpolateElement(
                                    __( 'You can get Client ID from <a>Google Cloud Console</a>. Make sure to enable Google Drive API.', 'wpmudev-plugin-test' ),
                                    {
                                        a: <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer" />,
                                    }
                                )}
                                label={ __( 'Client ID', 'wpmudev-plugin-test' ) }
                                value={credentials.clientId}
                                onChange={(value) => setCredentials({ ...credentials, clientId: value })}
                            />
                        </div>

                        <div className="sui-box-settings-row">
                            <TextControl
                                help={createInterpolateElement(
                                    __( 'You can get Client Secret from <a>Google Cloud Console</a>.', 'wpmudev-plugin-test' ),
                                    {
                                        a: <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer" />,
                                    }
                                )}
                                label={ __( 'Client Secret', 'wpmudev-plugin-test' ) }
                                value={credentials.clientSecret}
                                onChange={(value) => setCredentials({ ...credentials, clientSecret: value })}
                                type="password"
                            />
                        </div>

                        <div className="sui-box-settings-row">
                            {createInterpolateElement(
                                __( 'Please use this URL <em>%(uri)s</em> in your Google API\'s <strong>Authorized redirect URIs</strong> field.', 'wpmudev-plugin-test' ),
                                {
                                    em: <em>{ window.wpmudevDriveTest.redirectUri }</em>,
                                    strong: <strong />,
                                }
                            )}
                        </div>

                        <div className="sui-box-settings-row">
                            <p><strong>{ __( 'Required scopes for Google Drive API:', 'wpmudev-plugin-test' ) }</strong></p>
                            <ul>
                                <li>https://www.googleapis.com/auth/drive.file</li>
                                <li>https://www.googleapis.com/auth/drive.readonly</li>
                            </ul>
                        </div>
                    </div>
                    <div className="sui-box-footer">
                        <div className="sui-actions-right">
                            <Button
                                variant="primary"
                                onClick={handleSaveCredentials}
                                disabled={isLoading}
                                aria-label={ __( 'Save Google Drive API credentials', 'wpmudev-plugin-test' ) }
                            >
                                {isLoading ? <Spinner /> : __( 'Save Credentials', 'wpmudev-plugin-test' )}
                            </Button>
                        </div>
                    </div>
                </div>
            ) : !isAuthenticated ? (
                <div className="sui-box">
                    <div className="sui-box-header">
                        <h2 className="sui-box-title">{ __( 'Authenticate with Google Drive', 'wpmudev-plugin-test' ) }</h2>
                    </div>
                    <div className="sui-box-body">
                        <div className="sui-box-settings-row">
                            <p>{ __( 'Please authenticate with Google Drive to proceed with the test.', 'wpmudev-plugin-test' ) }</p>
                            <p><strong>{ __( 'This test will require the following permissions:', 'wpmudev-plugin-test' ) }</strong></p>
                            <ul>
                                <li>{ __( 'View and manage Google Drive files', 'wpmudev-plugin-test' ) }</li>
                                <li>{ __( 'Upload new files to Drive', 'wpmudev-plugin-test' ) }</li>
                                <li>{ __( 'Create folders in Drive', 'wpmudev-plugin-test' ) }</li>
                            </ul>
                        </div>
                    </div>
                    <div className="sui-box-footer">
                        <div className="sui-actions-left">
                            <Button
                                variant="secondary"
                                onClick={() => setShowCredentials(true)}
                                aria-label={ __( 'Change Google Drive API credentials', 'wpmudev-plugin-test' ) }
                            >
                                { __( 'Change Credentials', 'wpmudev-plugin-test' ) }
                            </Button>
                        </div>
                        <div className="sui-actions-right">
                            <Button
                                variant="primary"
                                onClick={handleAuth}
                                disabled={isLoading}
                                aria-label={ __( 'Start Google Drive authentication', 'wpmudev-plugin-test' ) }
                            >
                                {isLoading ? <Spinner /> : __( 'Authenticate with Google Drive', 'wpmudev-plugin-test' )}
                            </Button>
                        </div>
                    </div>
                </div>
            ) : (
                <>
                    {/* File Upload Section */}
                    <div className="sui-box">
                        <div className="sui-box-header">
                            <h2 className="sui-box-title">{ __( 'Upload File to Drive', 'wpmudev-plugin-test' ) }</h2>
                        </div>
                        <div className="sui-box-body">
                            <div className="sui-box-settings-row">
                                <input
                                    type="file"
                                    onChange={(e) => setUploadFile(e.target.files[0])}
                                    className="drive-file-input"
                                    aria-label={ __( 'Select a file to upload to Google Drive', 'wpmudev-plugin-test' ) }
                                    disabled={isUploading}
                                />
                                {uploadFile && (
                                    <p>
                                        <strong>{ __( 'Selected:', 'wpmudev-plugin-test' ) }</strong>{' '}
                                        {uploadFile.name} ({ Math.round(uploadFile.size / 1024) } KB)
                                    </p>
                                )}
                                {isUploading && (
                                    <div style={{ marginTop: '8px' }}>
                                        <strong>{ __( 'Uploading...', 'wpmudev-plugin-test' ) }</strong>{' '}
                                        {uploadProgress}%
                                        <div style={{
                                            height: '6px',
                                            background: '#e2e2e2',
                                            borderRadius: '3px',
                                            marginTop: '4px',
                                            overflow: 'hidden'
                                        }}>
                                            <div style={{
                                                width: uploadProgress + '%',
                                                background: '#0073aa',
                                                height: '100%',
                                                transition: 'width 0.25s'
                                            }}/>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                        <div className="sui-box-footer">
                            <div className="sui-actions-right">
                                <Button
                                    variant="primary"
                                    onClick={handleUpload}
                                    disabled={isUploading || !uploadFile}
                                    aria-label={ __( 'Upload the selected file to Google Drive', 'wpmudev-plugin-test' ) }
                                >
                                    {isUploading ? <Spinner /> : __( 'Upload to Drive', 'wpmudev-plugin-test' )}
                                </Button>
                            </div>
                        </div>
                    </div>

                    {/* Create Folder Section */}
                    <div className="sui-box">
                        <div className="sui-box-header">
                            <h2 className="sui-box-title">{ __( 'Create New Folder', 'wpmudev-plugin-test' ) }</h2>
                        </div>
                        <div className="sui-box-body">
                            <div className="sui-box-settings-row">
                                <TextControl
                                    label={ __( 'Folder Name', 'wpmudev-plugin-test' ) }
                                    value={folderName}
                                    onChange={setFolderName}
                                    placeholder={ __( 'Enter folder name', 'wpmudev-plugin-test' ) }
                                    disabled={isCreatingFolder}
                                />
                            </div>
                        </div>
                        <div className="sui-box-footer">
                            <div className="sui-actions-right">
                                <Button
                                    variant="secondary"
                                    onClick={handleCreateFolder}
                                    disabled={isCreatingFolder || !folderName.trim()}
                                    aria-label={ __( 'Create a new folder in Google Drive', 'wpmudev-plugin-test' ) }
                                >
                                    {isCreatingFolder ? <Spinner /> : __( 'Create Folder', 'wpmudev-plugin-test' )}
                                </Button>
                            </div>
                        </div>
                    </div>

                    {/* Files List Section */}
                    <div className="sui-box">
                        <div className="sui-box-header">
                            <h2 className="sui-box-title">{ __( 'Your Drive Files', 'wpmudev-plugin-test' ) }</h2>
                            <div className="sui-actions-right" style={{ display: 'flex', gap: '8px' }}>
                                <Button
                                    variant="secondary"
                                    onClick={() => loadFiles(false)}
                                    disabled={isLoadingFiles}
                                    aria-label={ __( 'Refresh Google Drive files list', 'wpmudev-plugin-test' ) }
                                >
                                    {isLoadingFiles ? <Spinner /> : __( 'Refresh Files', 'wpmudev-plugin-test' )}
                                </Button>
                                {nextPageToken && (
                                    <Button
                                        variant="secondary"
                                        onClick={() => loadFiles(true, nextPageToken)}
                                        disabled={isLoadingFiles}
                                        aria-label={ __( 'Load more Google Drive files', 'wpmudev-plugin-test' ) }
                                    >
                                        {isLoadingFiles ? <Spinner /> : __( 'Load More', 'wpmudev-plugin-test' )}
                                    </Button>
                                )}
                            </div>
                        </div>
                        <div className="sui-box-body">
                            {isLoadingFiles && files.length === 0 ? (
                                <div className="drive-loading">
                                    <Spinner />
                                    <p>{ __( 'Loading files...', 'wpmudev-plugin-test' ) }</p>
                                </div>
                            ) : files.length > 0 ? (
                                <div className="drive-files-grid">
                                    {files.map((file) => {
                                        const isFolder = file.isFolder || file.mimeType === 'application/vnd.google-apps.folder';
                                        return (
                                            <div key={file.id} className="drive-file-item">
                                                <div className="file-info">
                                                    <strong>{file.name}</strong>
                                                    <small>
                                                        {isFolder
                                                            ? __( 'Folder', 'wpmudev-plugin-test' )
                                                            : (file.mimeType || '—')}
                                                    </small>
                                                    <small>
                                                        {' · '}
                                                        {file.modifiedTime
                                                            ? new Date(file.modifiedTime).toLocaleString()
                                                            : __( 'Unknown date', 'wpmudev-plugin-test' )}
                                                        {!isFolder && file.size ? ' · ' + formatSize(file.size) : ''}
                                                    </small>
                                                </div>
                                                <div className="file-actions">
                                                    {file.webViewLink && (
                                                        <Button
                                                            variant="link"
                                                            size="small"
                                                            href={file.webViewLink}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            aria-label={ __( 'Open in Google Drive', 'wpmudev-plugin-test' ) }
                                                        >
                                                            { __( 'View in Drive', 'wpmudev-plugin-test' ) }
                                                        </Button>
                                                    )}
                                                    {!isFolder && (
                                                        <Button
                                                            variant="secondary"
                                                            size="small"
                                                            onClick={() => handleDownload(file.id, file.name)}
                                                            aria-label={ __( 'Download file from Google Drive', 'wpmudev-plugin-test' ) }
                                                        >
                                                            { __( 'Download', 'wpmudev-plugin-test' ) }
                                                        </Button>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : (
                                <div className="sui-box-settings-row">
                                    <p>{ __( 'No files found in your Drive. Upload a file or create a folder to get started.', 'wpmudev-plugin-test' ) }</p>
                                </div>
                            )}
                        </div>
                    </div>
                </>
            )}
        </>
    );
}

if ( createRoot ) {
    createRoot( domElement ).render(<StrictMode><WPMUDEV_DriveTest/></StrictMode>);
} else {
    render( <StrictMode><WPMUDEV_DriveTest/></StrictMode>, domElement );
}