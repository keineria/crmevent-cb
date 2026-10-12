<?php

namespace App\Services\Contacts;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The downloadable Excel to fill in before importing: its headers map to CRM fields on their own,
 * with an example row and a sheet that explains each column.
 */
class ContactTemplate
{
    public const FILE_NAME = 'plantilla-contactos-crm.xlsx';

    /**
     * Writes the template to a temporary file and returns its path.
     */
    public function write(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'plantilla').'.xlsx';
        $bold = (new Style)->setFontBold();

        $writer = new Writer;
        $writer->openToFile($path);

        $writer->getCurrentSheet()->setName('Contactos');
        $writer->addRow(Row::fromValues(array_keys(ImportFields::TEMPLATE), $bold));
        $writer->addRow(Row::fromValues(array_column(ImportFields::TEMPLATE, 1)));

        $writer->addNewSheetAndMakeItCurrent()->setName('Instrucciones');
        foreach ([
            ['Cómo usar esta plantilla'],
            ['1. Llena una fila por persona en la hoja «Contactos». Borra la fila de ejemplo antes de subirla.'],
            ['2. No cambies los títulos de la primera fila: así el CRM reconoce cada columna sin que tengas que asignarlas.'],
            ['3. Cada fila necesita al menos correo, documento o teléfono. Si la persona ya está en el CRM se completa, no se duplica.'],
            ['4. Los datos que ya están en el CRM no se sobrescriben; solo se llenan los vacíos.'],
            ['5. En Contactos › Importar Excel, sube el archivo y elige si son asistentes o interesados y el año.'],
            [''],
        ] as $line) {
            $writer->addRow(Row::fromValues($line, $line[0] === 'Cómo usar esta plantilla' ? $bold : null));
        }
        $writer->addRow(Row::fromValues(['Columna', 'Qué va', 'Ejemplo'], $bold));
        foreach (ImportFields::TEMPLATE as $header => [$field, $example, $help]) {
            $writer->addRow(Row::fromValues([$header, $help ?: ImportFields::LABELS[$field], $example]));
        }

        $writer->close();

        return $path;
    }
}
