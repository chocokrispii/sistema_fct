export interface AutoFirmaClient {
    setAppName(name: string): void;
    setServiceTimeout(timeout: number): void;
    cargarAppAfirma(): void;
    sign(
        data: string,
        algorithm: string,
        format: string,
        extraParams: string,
        onSuccess: (signature: string) => void,
        onError: (type: string, message: string) => void,
    ): void;
}

declare global {
    interface Window {
        AutoScript?: AutoFirmaClient;
    }
}

export function signPdfWithAutoFirma(pdfBase64: string): Promise<string> {
    return new Promise((resolve, reject) => {
        const client = window.AutoScript;
        if (!client) {
            reject(new Error('No se cargó el cliente web de AutoFirma.'));
            return;
        }

        try {
            client.setAppName('Sistema FCT');
            client.setServiceTimeout(120_000);
            client.cargarAppAfirma();
            const visibleSignatureParams = [
                'signaturePage=-1',
                'signaturePositionOnPageLowerLeftX=40',
                'signaturePositionOnPageLowerLeftY=40',
                'signaturePositionOnPageUpperRightX=300',
                'signaturePositionOnPageUpperRightY=95',
                'layer2Text=Firmado digitalmente por $$SUBJECTCN$$',
                'layer2FontFamily=1',
                'layer2FontSize=8',
                'layer2FontStyle=2',
                'layer2FontColor=gray',
            ].join('\n');
            client.sign(
                pdfBase64,
                'SHA256withRSA',
                'PAdES',
                visibleSignatureParams,
                (signature) => {
                    if (typeof signature !== 'string' || signature === '') {
                        reject(new Error('AutoFirma no devolvió el documento firmado.'));
                        return;
                    }
                    resolve(signature);
                },
                (_type, message) => reject(new Error(message || 'AutoFirma canceló o no pudo completar la firma.')),
            );
        } catch (cause) {
            reject(cause instanceof Error ? cause : new Error('No se pudo iniciar AutoFirma.'));
        }
    });
}

export function base64PdfBlob(pdfBase64: string): Blob {
    const binary = atob(pdfBase64);
    const bytes = new Uint8Array(binary.length);
    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }
    return new Blob([bytes], { type: 'application/pdf' });
}
