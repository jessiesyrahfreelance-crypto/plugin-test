import { createRoot, render, StrictMode, useState, useEffect, createInterpolateElement } from '@wordpress/element';
import { Button, TextControl, Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import "./scss/style.scss"

const domElement = document.getElementById( window.wpmudevDriveTest.dom_element_id );

const WPMUDEV_DriveTest = () => {
    const [isAuthenticated, setIsAuthenticated] = useState(window.wpmudevDriveTest.authStatus || false);
    const [hasCredentials, setHasCredentials] = useState(window.wpmudevDriveTest.hasCredentials || false);
    const [showCredentials, setShowCredentials] = useState(!window.wpmudevDriveTest.hasCredentials);
    const [isLoading, setIsLoading] = useState(false);
    const [files, setFiles] = useState([]);
    const [uploadFile, setUploadFile] = useState(null);
    const [folderName, setFolderName] = useState('');
    const [notice, setNotice] = useState({ message: '', type: '' });
    const [credentials, setCredentials] = useState({
        clientId: '',
        clientSecret: ''
    });

    useEffect(() => {
        setShowCredentials(!hasCredentials);
    }, [hasCredentials]);

    const showNotice = (message, type = 'success') => {
        setNotice({ message, type });
        setTimeout(() => setNotice({ message: '', type: '' }), 5000);
    };

    const handleSaveCredentials = async () => {
        const clientId = (credentials.clientId || '').trim();
        const clientSecret = (credentials.clientSecret || '').trim();

        if (!clientId || !clientSecret) {
            showNotice(__('Please enter Client ID and Client Secret.', 'wpmudev-plugin-test'), 'error');
            return;
        }

        setIsLoading(true);
        try {
            const response = await apiFetch({
                path: '/' + window.wpmudevDriveTest.restEndpointSave,
                method: 'POST',
                headers: {
                    'X-WP-Nonce': window.wpmudevDriveTest.nonce,
                },
                data: {
                    client_id: clientId,
                    client_secret: clientSecret,
                },
            });

            // Accept either boolean true or { success: true } payloads
            const ok = response === true || (response && response.success);
            if (!ok) {
                const message = (response && (response.message || response.data?.message)) || __('Failed to save credentials.', 'wpmudev-plugin-test');
                throw new Error(message);
            }

            setHasCredentials(true);
            setShowCredentials(false);
            setCredentials({ clientId: '', clientSecret: '' });
            showNotice(__('Credentials saved successfully. You can now authenticate with Google Drive.', 'wpmudev-plugin-test'), 'success');
        } catch (err) {
            showNotice(err?.message || __('Failed to save credentials. Please try again.', 'wpmudev-plugin-test'), 'error');
        } finally {
            setIsLoading(false);
        }
    };

    // Placeholders for next tasks
    const handleAuth = async () => {};
    const loadFiles = async () => {};
    const handleUpload = async () => {};
    const handleDownload = async (fileId, fileName) => {};
    const handleCreateFolder = async () => {};

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
                    {/* Sections for later tasks */}
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