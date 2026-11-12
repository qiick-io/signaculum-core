/** A signed-in browser of the current user (Settings → Sessions). */
export type BrowserSession = {
    publicId: string;
    ipAddress: string | null;
    browser: string;
    platform: string;
    isCurrentDevice: boolean;
    /** Unix seconds. */
    lastActiveAt: number;
};

/** An application the user authorized (Settings → Connected apps). */
export type ConnectedApplication = {
    clientId: string;
    name: string;
    scopes: string[];
    /** Unix seconds. */
    firstAuthorizedAt: number;
    /** Unix seconds. */
    lastAuthorizedAt: number;
};
