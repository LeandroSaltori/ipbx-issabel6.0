### ALTERAÇÃO DE LINK DO BANCO DE DADOS
    
    - No menu principal, no logo, altera o link padrão do issabel.

    - Acesse o banco de dados:
    
    mysql -u root -p

    USE asterisk;

    UPDATE issabelpbx_settings SET value='https://www.prismatelecom.com' WHERE keyword='BRAND_IMAGE_ISSABELPBX_LINK_LEFT';
    UPDATE issabelpbx_settings SET value='https://www.prismatelecom.com' WHERE keyword='BRAND_IMAGE_ISSABELPBX_LINK_FOOT';